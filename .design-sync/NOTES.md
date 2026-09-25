# Claude Design sync notes

- This project is a **brand kit**, not a component library: the package ships no UI
  components. The Claude Design project holds only tokens (`--wpn-*`, light/dark), typography,
  the brand brief (`art/BRIEF.md` copied to `guidelines/brand-brief.md`) and two preview cards
  (Palette, Typography).
- The local upload directory `ds-bundle/` is hand-authored and git-ignored; regenerate it from
  `art/BRIEF.md` when the brief changes.
- No `_ds_sync.json` anchor is uploaded (no bundle, no compiled components): a re-sync simply
  re-uploads the few files.
- Deliverables designed in the project are exported to `art/` under the file names listed in
  `art/BRIEF.md` (excluded from the Composer archive by `.gitattributes`).
