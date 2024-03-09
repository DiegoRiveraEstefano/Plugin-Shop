<?php

namespace Azuriom\Plugin\Shop\Payment;


class CurrenciesToCountries
{
    protected const countrieList = [
        "CLP" => "CL",
    ];

    public static function countries(): array
    {
        return self::COUNTRIES;
    }

    public static function codes(): array
    {
        return array_keys(self::COUNTRIES);
    }
}
