# Consistency and concurrency

Where the package is strictly consistent, where it is **eventually** consistent on purpose, and
what you observe in each case.

| Case | Guarantee | What you may observe |
|---|---|---|
| [Quota without a shared lock store](#1-quota-without-a-shared-lock-store) | Soft | N+1 active devices, transiently |
| [Anonymous cap](#2-anonymous-cap) | Soft, always | Slightly more than `max_active` under concurrency |
| [Async queue and owner change](#3-async-queue-and-owner-change) | Never delivered to the wrong owner | The message is dropped |
| [VAPID rotation](#4-vapid-rotation) | Converges when browsers come back | 401/403 (`Permanent(vapid)`) until re-subscription |
| [Events and transactions](#5-events-are-published-after-the-real-commit) | After the outermost commit only | Listeners run late (after your outer transaction), never on rollback |
| [Purge](#6-purge-emits-only-subscriptionspurged) | Bulk | One aggregated event, no per-subscription event |
| [Lock ordering](#7-lock-ordering) | No deadlock | 503 after 5 s when a lock cannot be acquired |
| [Unique index race](#8-unique-index-race-replayed-once) | One row per endpoint | One replay, invisible to the client |
| [Expiry versus re-registration](#9-expiry-versus-re-registration) | Serialized on the endpoint | A retired device that comes back is reactivated |
| [iOS click landing](#10-ios-click-landing) | Eventually | The page navigates when it thaws |

## 1. Quota without a shared lock store

`max_subscriptions_per_subscriber` (16) is enforced inside the registration transaction, under
the owner lock (`webpush:owner:<sha256(subscriberId)>`). If the lock does not exclude other
processes (Symfony without `lock.factory`, which falls back on `LocalSubscriptionLock`; Laravel
with an `array` store, or `file` on several hosts), two concurrent registrations of **different**
endpoints for the same subscriber may both see 15 devices and both insert: 17 devices.

It is corrected the next time a device newly counts for that subscriber (registered, reassigned
or reactivated; a plain renewal does not apply the quota), which evicts down to the limit (least
recently registered first). Use a shared store (Redis, database) for a strict quota.

## 2. Anonymous cap

`anonymous.max_active` counts active anonymous rows; there is **deliberately no global lock**
(it would serialize every anonymous registration of the site and answer 503 under load). Under
concurrency the cap may be exceeded by the number of in-flight registrations. It is a resource
bound, not a security invariant: the rate limiter is the front line.

## 3. Async queue and owner change

With `messenger` / `queue`, each queued delivery carries the subscription id **and the owner at
dispatch time**. Between dispatch and consumption the device may have been reassigned (a shared
browser signed in to another account), revoked, expired or evicted. The handler rebuilds a
`SubscriptionsAudience([id], expectedOwner)`; `DeliverPayload` then records
`Skipped(owner_changed)` or `Skipped(not_active)` and **does not retry**. The previous owner's
notification never reaches the new owner; it is simply lost for that device.

Symmetrically, a device registered after the dispatch does not receive the message: the fan-out
happens at dispatch time.

## 4. VAPID rotation

After a key pair change, push services answer 401/403 for every existing subscription. The
package classifies it `Permanent(vapid)`, logs it as critical and **does not retire** the
subscription (a wrong key deployed by mistake must not empty the table). Each browser converges
when its page runs `startWebPush()`: `sync()` notices that the subscription was created with
another `applicationServerKey`, unsubscribes it (proof of possession), subscribes again with the
new key and registers it (`SyncResult` `'rotated'`). A device whose user never comes back keeps
failing; revoke it or delete it yourself.

## 5. Events are published after the real commit

Domain events are collected in the transaction and handed to `TransactionBoundary::afterCommit()`,
which runs them once the **outermost** transaction commits and drops them on rollback. A
synchronous listener therefore never receives a fact that a rollback undid, even when you call a
use case inside your own transaction:

- **Symfony / Doctrine**: `DoctrineTransactionBoundary` defers to `AfterCommitCallbacks`, flushed
  by a DBAL driver middleware (`AfterCommitMiddleware`, tag `doctrine.middleware` on the configured
  connection). The driver connection only sees the outermost `commit()`: nested transactions are
  savepoints handled by the wrapper connection. Outside any transaction, callbacks run at once.
- **Laravel**: `DbTransactionBoundary` uses the connection's `afterCommit()`, i.e. `DB::afterCommit()`
  semantics. Queued jobs are also dispatched `afterCommit()`.

Consequence: in a long outer transaction, listeners run when it ends, not when the use case
returns.

## 6. Purge emits only `SubscriptionsPurged`

`webpush:purge` / `web-push:purge` delete rows in bulk (`deleteRetiredBefore()`,
`deleteStaleAnonymousBefore()`), bypassing the aggregates. They emit **one**
`SubscriptionsPurged(retired, abandonedAnonymous, occurredAt)`, never a
`SubscriptionUnsubscribed` per row. Likewise `RemoveAllSubscriptions` emits one
`SubscriptionUnsubscribed(account_removed)` per **active** device, and deletes the retired
leftovers in bulk without events. Do not build a per-device projection on unsubscription events
alone.

## 7. Lock ordering

Every flow that locks acquires the keys in one global order: **the endpoint key first**
(`webpush:endpoint:<fingerprint>`), **then the owner keys sorted** (`webpush:owner:<hash>`), and
releases them in reverse order. A total order makes deadlocks impossible.

| Flow | Keys |
|---|---|
| `RegisterSubscription` | endpoint; then (under it) the current owner of the endpoint + the claimant |
| `Unsubscribe` | endpoint |
| `RevokeSubscription` | endpoint + owner |
| `RetireOnExpiry` | endpoint |
| `RemoveAllSubscriptions` | owner |

Holding the endpoint key while reading the current owner is what makes the owner impossible to
change under a registration: every flow that changes an endpoint's owner holds that key. Locks
have a 30 s TTL (a crashed process frees them) and are waited for 5 s at most, then the request
answers **503** (`LockNotAcquired`): retrying is always safe.

## 8. Unique index race, replayed once

The unique index on `endpoint_hash` is the last line. If two registrations of the same endpoint
race without a shared lock, the second insert violates it; the adapter translates the violation
into `SubscriptionAlreadyExists`, and `SubscriptionTransaction` **replays the work once**: the
second attempt finds the row and takes the renew/reassign path of the matrix. The client sees a
normal 204.

## 9. Expiry versus re-registration

`RetireOnExpiry` (mandatory, after every delivery) takes the endpoint lock and opens its own
transaction, reloads the subscription by id, and only retires it if it is still active. It never
touches a row that was deleted in the meantime. With `retirement: deactivate`, a retired device
that registers again is **reactivated** (and counted in the quota again).

## 10. iOS click landing

On an installed iOS PWA, a page frozen in the background thaws at an unpredictable moment, and
`navigate()` may resolve without moving it. The worker writes a navigation intent before trying
to land; the page claims it on `pageshow`, `focus`, `visibilitychange` or when nudged. The claim
is atomic across tabs (whoever wins the `cache.delete()` navigates) and expires after 5 minutes.
Landing is eventually consistent by design; no timer is involved.
