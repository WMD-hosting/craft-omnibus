<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\models;

use craft\base\Model;
use wmd\craftproductpricehistory\helpers\PriceListFiles;

/**
 * One "prodajni objekt" (NN 101/2026): the web shop, or a physical store. Each
 * outlet gets its own price-list file every day (čl. VI names the file after
 * the outlet's form, address and code).
 *
 * The plugin settings define the web shop; sites add physical stores through
 * PriceListService::EVENT_REGISTER_OUTLETS.
 *
 * @since 1.2.0
 */
class PriceListOutlet extends Model
{
    /** @var string Oznaka prodajnog objekta, unique per outlet (e.g. the inventory location handle). */
    public string $code = '';

    /** @var string Oblik prodajnog objekta, e.g. "internetska trgovina", "prodavaonica". */
    public string $type = '';

    /** @var string Adresa prodajnog objekta, street and city. */
    public string $address = '';

    /** @var string Name shown on the public index; defaults to the code. */
    public string $name = '';

    /**
     * @var int|null Commerce inventory location whose stock decides `dostupnost`.
     * Null keeps the web shop's availability (stock summed over all locations).
     */
    public ?int $inventoryLocationId = null;

    /**
     * The file-name prefix: oblik, adresa and oznaka, ASCII and kebab-cased.
     */
    public function prefix(): string
    {
        return PriceListFiles::prefix($this->type, $this->address, $this->code);
    }

    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : $this->code;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['code', 'type'], 'required'],
            [['address', 'name'], 'string'],
            [['inventoryLocationId'], 'integer'],
        ];
    }
}
