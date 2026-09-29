# Changelog

## [1.15.1](https://github.com/byte8io/magento-pulsar/compare/v1.15.0...v1.15.1) (2026-09-29)


### Bug Fixes

* stop media_integrity flagging legitimate tmp uploads as unexpected ([7f59256](https://github.com/byte8io/magento-pulsar/commit/7f5925647512255fdc7a0191b63af56293a2e5c4))

## [1.15.0](https://github.com/byte8io/magento-pulsar/compare/v1.14.0...v1.15.0) (2026-09-26)


### Features

* pull detection signatures from Pulsar feed (v1.14.0) ([bd6dd3f](https://github.com/byte8io/magento-pulsar/commit/bd6dd3f9567c36454bb17d849631473f97062b46))


### Documentation

* public README — product links, collectors, install, config ([f8f4020](https://github.com/byte8io/magento-pulsar/commit/f8f4020bab8c66a0c7451b1fc08a3fa337db969d))

## [1.14.0](https://github.com/byte8io/magento-pulsar/compare/v1.13.0...v1.14.0) (2026-09-14)


### Features

* applied security patch inventory + verification collector ([604863d](https://github.com/byte8io/magento-pulsar/commit/604863d26d342eb85475cb2ab4c00e24e343ab25))

## [1.13.0](https://github.com/byte8io/magento-pulsar/compare/v1.12.1...v1.13.0) (2026-08-30)


### Features

* add transactional_email collector for unsent sales emails ([1e2b71a](https://github.com/byte8io/magento-pulsar/commit/1e2b71ab3fceaaddf479002b076a800ff9abdcc1))


### Bug Fixes

* stabilize log-error signature against embedded timestamps ([8730377](https://github.com/byte8io/magento-pulsar/commit/8730377789d91caac28e50e74fe17cfa36b66e81))

## [1.12.1](https://github.com/byte8io/magento-pulsar/compare/v1.12.0...v1.12.1) (2026-06-21)


### Bug Fixes

* allowlist common third-party script hosts in content_integrity ([7ad6d76](https://github.com/byte8io/magento-pulsar/commit/7ad6d7650e64fd851818008b7e6f89d1c5bb667e))
* defang literal IOC domains in content_integrity to stop AV false positives ([36eeeac](https://github.com/byte8io/magento-pulsar/commit/36eeeac20d6a815447d386ce59f5b5c1fc3c54c7))

## [1.12.0](https://github.com/byte8io/magento-pulsar/compare/v1.11.0...v1.12.0) (2026-06-17)


### Features

* window queue errors to 24h + dead-consumer signal ([fd197ca](https://github.com/byte8io/magento-pulsar/commit/fd197ca6a6c345b8450e5f07c8f0a40c393486c5))

## [1.11.0](https://github.com/byte8io/magento-pulsar/compare/v1.10.1...v1.11.0) (2026-06-15)


### Features

* add ContentIntegrityCollector + compromised status ([8c6723e](https://github.com/byte8io/magento-pulsar/commit/8c6723ec513f0e2b8cb94cef3a8b72e754c01e88))

## [1.10.1](https://github.com/byte8io/magento-pulsar/compare/v1.10.0...v1.10.1) (2026-05-04)


### Bug Fixes

* flatten ACL to avoid duplicate Magento_Config::config ([42cc36d](https://github.com/byte8io/magento-pulsar/commit/42cc36dcb3ccaf205dbc2a6f9f9b8a90dda70b8b))
* per-subdir thresholds in media_integrity to stop import false positives ([55eed03](https://github.com/byte8io/magento-pulsar/commit/55eed0394db8fed69007fb62ce92a0318aa41ebf))
