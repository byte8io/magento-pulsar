<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Math\Random;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;

class ApiKey extends Value
{
    private const KEY_LENGTH = 32;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly Random $random,
        private readonly EncryptorInterface $encryptor,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave(): self
    {
        $value = $this->getValue();

        if (empty($value)) {
            $value = $this->generateApiKey();
        }

        $this->setValue($this->encryptor->encrypt($value));

        return parent::beforeSave();
    }

    protected function _afterLoad(): self
    {
        parent::_afterLoad();
        $value = $this->getValue();
        if ($value) {
            $this->setValue($this->encryptor->decrypt($value));
        }
        return $this;
    }

    private function generateApiKey(): string
    {
        return 'psk_' . $this->random->getRandomString(self::KEY_LENGTH, Random::CHARS_LOWERS . Random::CHARS_DIGITS);
    }
}
