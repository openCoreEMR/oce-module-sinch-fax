# Changelog

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
