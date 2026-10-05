<?php

namespace App\Notifications\Concerns;

use Illuminate\Support\Str;

/**
 * Identifiant de notification ordonné dans le temps. La colonne created_at n'a qu'une précision
 * d'une seconde : deux notifications créées dans la même seconde (ex. « prise en charge » puis « en
 * traitement ») seraient sinon listées dans un ordre indéterminé, l'UUID aléatoire de Laravel ne
 * servant pas de départage. Avec un UUID « timestamp-first », trier sur (created_at, id) redonne
 * l'ordre réel de création.
 */
trait HasOrderedId
{
    protected function useOrderedId(): void
    {
        $this->id = (string) Str::orderedUuid();
    }
}
