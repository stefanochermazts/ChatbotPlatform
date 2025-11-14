<?php

namespace App\Enums\Scraper;

enum TitleStrategy: string
{
    case TITLE = 'title';
    case H1 = 'h1';
    case H1_H2 = 'h1+h2';

    public static function default(): self
    {
        return self::TITLE;
    }
}
