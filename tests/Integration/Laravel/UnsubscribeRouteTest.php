<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Testing\TestBrowser;

final class UnsubscribeRouteTest extends LaravelTestCase
{
    #[Test]
    public function it_should_delete_the_subscription_of_a_device_proving_possession_without_session(): void
    {
        $browser = TestBrowser::chrome();
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), $browser);

        $response = $this->unsubscribe($browser->endpoint(), $browser->auth());

        $response->assertNoContent();
        self::assertFalse($this->repository()->hasFingerprint($browser->address()->fingerprint()));
    }

    #[Test]
    public function it_should_keep_the_subscription_and_answer_the_same_204_to_a_wrong_proof(): void
    {
        $browser = TestBrowser::chrome();
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), $browser);

        $response = $this->unsubscribe($browser->endpoint(), 'AAAAAAAAAAAAAAAAAAAAAA');

        $response->assertNoContent();
        self::assertTrue($this->repository()->hasFingerprint($browser->address()->fingerprint()));
    }

    #[Test]
    public function it_should_answer_the_same_204_for_an_unknown_endpoint(): void
    {
        $response = $this->unsubscribe(TestBrowser::chrome('unknown')->endpoint(), 'AAAAAAAAAAAAAAAAAAAAAA');

        $response->assertNoContent();
    }

    /**
     * @return TestResponse<Response>
     */
    private function unsubscribe(string $endpoint, string $auth): TestResponse
    {
        $body = json_encode(['endpoint' => $endpoint, 'keys' => ['auth' => $auth]], \JSON_THROW_ON_ERROR);

        return $this->call('POST', '/web-push/subscriptions/unsubscribe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }
}
