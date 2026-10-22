# Changelog

## [0.2.0](https://github.com/dylf/pelican-gale-sync/compare/v0.1.0...v0.2.0) (2026-10-22)


### Features

* color the sync list by action ([c48e27c](https://github.com/dylf/pelican-gale-sync/commit/c48e27c9588385088dfec8eaa8a0d61e78c20adb))
* gale profile sync for pelican servers ([d152e6b](https://github.com/dylf/pelican-gale-sync/commit/d152e6b5049a64b5a9715b02df8c52b4d491a9c5))
* labelled action buttons and a reset-status action ([eb3768a](https://github.com/dylf/pelican-gale-sync/commit/eb3768aad15da4f3b0424e163a496a8665dcd039))
* list every mod of the profile with its status; progress overlays the list ([2b1a4ab](https://github.com/dylf/pelican-gale-sync/commit/2b1a4abcd2a68d24ed999b96691e70924d077fb6))
* live sync progress at the top of the page; retry Wings calls; refresh the plan after a partial failure ([0292313](https://github.com/dylf/pelican-gale-sync/commit/02923134172e2fda1095dddae1a0bb2425d8eb1d))
* run syncs from the scheduler instead of the queue ([99395be](https://github.com/dylf/pelican-gale-sync/commit/99395be39ae3d3f7721b4f41cd0efb8c6003322d))
* use the wind icon for the sidebar and permissions ([f221d9a](https://github.com/dylf/pelican-gale-sync/commit/f221d9a8ff8a54ab470b75aa5601575afa0c3da0))


### Bug Fixes

* bring back ProgressStore::forget() ([cdae88b](https://github.com/dylf/pelican-gale-sync/commit/cdae88b47140ea51a4eb450bf34e1deaff14cf99))
* don't overlay another sync's progress on the plan ([3ed0f9f](https://github.com/dylf/pelican-gale-sync/commit/3ed0f9f942b9f72cc721eeaaea726da98b3fe347))
* queued state that expires, tolerant deletes, fewer directory listings ([951c49f](https://github.com/dylf/pelican-gale-sync/commit/951c49ff1173f03c85a18d9e304dd9a7bc4e1eeb))
* use laravel storage for scratch zips instead of raw file functions ([8a5ea61](https://github.com/dylf/pelican-gale-sync/commit/8a5ea6120e2d60776744597d3624bac51c144111))
