<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PatchSources implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'composer_json',
                'label' => __('composer.json extra.patches (cweagans / vaimo composer-patches)'),
            ],
            [
                'value' => 'installed_json',
                'label' => __('vendor/composer/installed.json (patches recorded at install time)'),
            ],
            [
                'value' => 'patch_dirs',
                'label' => __('Archived .patch files in patch directories (git apply workflow)'),
            ],
        ];
    }
}
