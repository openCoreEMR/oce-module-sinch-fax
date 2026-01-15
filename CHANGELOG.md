# Changelog

## [0.5.4](https://github.com/openCoreEMR/oce-module-sinch-fax/compare/0.5.3...0.5.4) (2026-01-15)


### Features

* display configured fax phone numbers in module UI ([#74](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/74)) ([f99e087](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/f99e08780b62efeb88ab0c15f15139c5db54c3f3)), closes [#71](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/71)
* return 404 for all web requests when module not installed/enabled ([#75](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/75)) ([f913a6b](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/f913a6bf7231535a52d78be7e83fed43fba2812d)), closes [#72](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/72)


### Bug Fixes

* **.env.testing.example:** s/whitelist/allowlist ([#73](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/73)) ([c5d28ca](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/c5d28ca89744ca24887471c6c0750e60c2ad169d))
* move dlgclose script from controller echo to template ([#76](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/76)) ([ecf8fc8](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/ecf8fc87ff2bcc9f954ae4580b3e081bb14b4f27))
* remove auto-enabled coverage config from phpunit.xml ([#78](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/78)) ([11161c9](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/11161c9469b15c709bcbfb51cdd4fa1f9b5184d6))
* **webhook:** enforce authentication on webhook endpoint ([#84](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/84)) ([20dc525](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/20dc5257ac51d38afa6133665d9286c5e08fd362)), closes [#83](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/83)


### Code Refactoring

* **exceptions:** replace generic \Exception with custom exception types ([#82](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/82)) ([dea5da1](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/dea5da11cf3bb63552aa4d06124118d67dad2002))
* **rector:** use full prepared sets and apply fixes ([#81](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/81)) ([decb3f1](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/decb3f1a50f141b29a62e26738370324cd44f3c3))

## [0.5.3](https://github.com/openCoreEMR/oce-module-sinch-fax/compare/0.5.2...0.5.3) (2026-01-12)


### Code Refactoring

* rename IP whitelist to allowlist ([#68](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/68)) ([c85749c](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/c85749c707ab3b52fba7dc0285186a8f5a4d6b9f))

## [0.5.2](https://github.com/openCoreEMR/oce-module-sinch-fax/compare/0.5.1...0.5.2) (2026-01-12)


### Features

* add CLI tool for module installation using Symfony Console ([#66](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/66)) ([c13a879](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/c13a8794945cef34e69951577bcbb7310d63bc12))
* add environment-based configuration mode ([#61](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/61)) ([20483ee](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/20483ee448098d6d4ffbb7298a9c8ececbd5975e)), closes [#48](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/48)
* add webhook support for inbound fax events ([#56](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/56)) ([12a3587](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/12a3587a90fa7c7a6543fc14cb9833e2e4b52124))


### Documentation

* **@link:** use opencoreemr.com url ([#46](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/46)) ([bae482b](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/bae482bef0233b088ffbf5cda7b0cda567bb19df))
* **README:** receive fax and move to patient ([#35](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/35)) ([0f747b6](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/0f747b6cf82a318b4ecefcbdd7f80eee406db8cc))
* reorganize CLAUDE.md into modular documentation ([#54](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/54)) ([e8f5718](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/e8f5718bcb6389bc697e82eda3762fafef29ffe5))


### Code Refactoring

* improve type safety for PHPStan level 8 compliance ([#57](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/57)) ([c987a35](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/c987a350b8239b7e24403ed5145ff01ef97e61d4))
* **php:** apply rector updates ([#43](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/43)) ([020d68b](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/020d68b140ed09ae8dac52c75570f14983807854))


### Dependencies

* bump actions/cache from 4 to 5 ([#41](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/41)) ([0696278](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/069627832f3a91fb366506f87db1286a321c1f04))
* bump actions/cache from 4 to 5 ([#53](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/53)) ([40b9564](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/40b95649ef55850a9f56f033cce1f10bb2fdfe37))
* bump actions/checkout from 4 to 6 ([#51](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/51)) ([5011603](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/5011603949995688792b005823364c95e13c5195))
* bump actions/upload-artifact from 4 to 6 ([#52](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/52)) ([0992b49](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/0992b4916d0fb9c4968f055eda6c55bfec19e5f1))

## [0.5.1](https://github.com/openCoreEMR/oce-module-sinch-fax/compare/0.5.0...0.5.1) (2025-12-04)


### Bug Fixes

* **version:** update DEFAULT_VERSION to 0.5.0 and add release-please marker ([#34](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/34)) ([8a83fe6](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/8a83fe6812052f2533957e8d08352d84cf958786))


### Dependencies

* **github-actions:** bump actions/checkout from 4 to 6 ([#29](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/29)) ([b88d376](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/b88d376eed246d5fae452c427f0a0bba2f5ae4b1))

## [0.5.0](https://github.com/openCoreEMR/oce-module-sinch-fax/compare/0.1.0...0.5.0) (2025-12-04)


### Features

* polling for faxes ([#3](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/3)) ([afc227c](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/afc227c6366b13207d06699fd5a5d41b47cf6995))


### Bug Fixes

* add templates/.gitkeep to fix composer install ([b1597d6](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/b1597d6c71c3b28f268b3fe8190a655edc0f6499))
* allow sending MS Word documents ([#9](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/9)) ([4621127](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/4621127ba2b4803ee9a8c866a521fc9ba44396c2))
* configuration and sending faxes ([#1](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/1)) ([ea1d0c9](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/ea1d0c9b1cdadc589f0daff19f6bea3d1fd3aee6))
* don't corrupt files on send ([#8](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/8)) ([1fd5c21](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/1fd5c214c48e202d12ae0539c3d6b1b30337208c))
* **phpstan:** proper type annotations ([ae63cbf](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/ae63cbf07fb8f6f8728278239709f448e09dfe05))
* **public/index.php:** csrf in post ([#7](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/7)) ([dea6513](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/dea6513f808bdcb58c91f4c9d406467ace5669b4))
* remove references to OEGlobalsBag ([#6](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/6)) ([735e23f](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/735e23fd4e0f5355aacf0bf51a50b94e63c33f63))
* use QueryUtils, not raw sql ([#5](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/5)) ([094adc6](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/094adc6e962e744c4ca50eefaeaf77f5cc5cd6c3))


### Documentation

* **README:** module name and screenshots ([#13](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/13)) ([237d241](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/237d241e60df11eaf470f1eed08347a0313a835c))


### Miscellaneous Chores

* release 0.5.0 ([17b60b9](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/17b60b93517c8f6aaaf9135d43fe7af74bf07d94))


### Code Refactoring

* use globalsbag to access globals ([#2](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/2)) ([6fa9cc6](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/6fa9cc6e03005799fd5b9a9b7b12673375649a8d))
* use symfony and twig ([#18](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/18)) ([0ebfb5b](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/0ebfb5ba80b503906b4ccdff80caf58cb9b0fd2a))


### Build System

* **version:** try to supply version from git ([#12](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/12)) ([7edeec5](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/7edeec5ff09d51ce6bc202480e74db0b76638c97))


### Dependencies

* **dev:** update squizlabs/php_codesniffer requirement ([#24](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/24)) ([7ea3ce2](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/7ea3ce29ce63a7d7b1611ce499101808d617c63a))
* **github-actions:** bump actions/cache from 3 to 4 ([#23](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/23)) ([337f6c7](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/337f6c757e5bf9c79e694153a298d2ee692acd16))
* **github-actions:** bump actions/checkout from 4 to 6 ([#25](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/25)) ([dcf5b6e](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/dcf5b6edef352684a834ad7bb3b88d10580d166f))
* **github-actions:** bump amannn/action-semantic-pull-request from 5 to 6 ([#26](https://github.com/openCoreEMR/oce-module-sinch-fax/issues/26)) ([ed3680f](https://github.com/openCoreEMR/oce-module-sinch-fax/commit/ed3680ffb319f6a4f5c49d730b2e35bfeb69f923))
