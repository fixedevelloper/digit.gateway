<?php

namespace App\Console\Commands;

use App\Models\Agency;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAgencyCommand extends Command
{
    /**
     * Le nom et la signature de la commande de la console.
     *
     * @var string
     */
    protected $signature = 'agency:create
                            {name : Le nom de l\'agence}
                            {city : La ville de l\'agence (ex: Douala, Yaoundé)}
                            {--code= : Optionnel : Spécifier un code manuellement}';

    /**
     * La description de la commande de la console.
     *
     * @var string
     */
    protected $description = 'Créer une nouvelle agence et générer son code unique pour QR Code';

    /**
     * Exécuter la commande de la console.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $city = $this->argument('city');
        $codeOption = $this->option('code');

        // Génération automatique du code si non fourni (ex: DLA-CREATIVE-SOLUTIONS)
        if ($codeOption) {
            $code = strtoupper($codeOption);
        } else {
            $prefix = strtoupper(substr(clean_string($city), 0, 3));
            $slug = strtoupper(Str::slug($name));
            $code = "AGENCY-{$prefix}-{$slug}";
        }

        // Vérification si le code existe déjà
        if (Agency::where('code', $code)->exists()) {
            $this->error("Une agence avec le code [{$code}] existe déjà !");
            return Command::FAILURE;
        }

        // Demande de confirmation des détails dans le terminal
        $this->info("Détails de la nouvelle agence :");
        $this->line("Nom : {$name}");
        $this->line("Ville : {$city}");
        $this->line("Code généré (pour le QR Code) : {$code}");

        if ($this->confirm('Voulez-vous enregistrer cette agence ?', true)) {
            $agency = Agency::create([
                'name' => $name,
                'city' => $city,
                'code' => $code,
                'status' => 'active',
            ]);

            $this->info("L'agence [{$agency->name}] a été créée avec succès !");
            $this->info("Code à mettre dans votre générateur de QR Code : {$agency->code}");
            return Command::SUCCESS;
        }

        $this->warn("Création annulée.");
        return Command::INVALID;
    }
}

/**
 * Fonction d'aide pour nettoyer la chaîne pour le préfixe de la ville
 */
function clean_string($string) {
    return preg_replace('/[^A-Za-z0-9\-]/', '', $string);
}
