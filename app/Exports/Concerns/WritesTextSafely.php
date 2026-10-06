<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * Les cellules texte sont écrites comme du TEXTE, jamais interprétées : un numéro de bénéficiaire ou un nom saisi par
 * un marchand comme « =HYPERLINK(...) » ne doit pas devenir une formule à l'ouverture du fichier par l'équipe.
 */
trait WritesTextSafely
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        return (new StringValueBinder)
            ->setNumericConversion(false)
            ->setBooleanConversion(false)
            ->setNullConversion(false)
            ->bindValue($cell, $value);
    }
}
