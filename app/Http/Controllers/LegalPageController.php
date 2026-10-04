<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Pages publiques des documents légaux (URL exigée par les stores) : /privacy et /terms.
 * Contenu dans resources/legal/*.php, informations de l'éditeur dans config/legal.php.
 */
class LegalPageController extends Controller
{
    public function privacy(Request $request)
    {
        return $this->render($request, 'privacy', config('legal.privacy_version'));
    }

    public function terms(Request $request)
    {
        return $this->render($request, 'terms', config('legal.terms_version'));
    }

    private function render(Request $request, string $document, string $version)
    {
        $lang = $request->query('lang', $request->getPreferredLanguage(['fr', 'en']) ?? 'fr');
        $lang = $lang === 'en' ? 'en' : 'fr';

        $content = require resource_path("legal/{$document}.php");

        // Remplace {company}, {support_email}… par la configuration ; échappement fait dans la vue
        $replacements = [];
        foreach (config('legal.info') as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }
        $fill = fn (string $text) => strtr($text, $replacements);

        return view('legal', [
            'lang' => $lang,
            'document' => $document,
            'title' => $content['title'][$lang],
            'version' => $version,
            'sections' => collect($content['sections'])->map(fn ($s) => [
                'title' => $fill($s['title'][$lang]),
                'paragraphs' => collect($s['paragraphs'])->map(fn ($p) => $fill($p[$lang]))->all(),
            ])->all(),
            'otherDocument' => $document === 'privacy' ? 'terms' : 'privacy',
            'otherTitle' => ($document === 'privacy'
                ? require resource_path('legal/terms.php')
                : require resource_path('legal/privacy.php'))['title'][$lang],
        ]);
    }
}
