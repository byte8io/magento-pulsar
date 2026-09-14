# Changelog

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
