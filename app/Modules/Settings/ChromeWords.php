<?php

namespace App\Modules\Settings;

use App\Core\Db;
use App\Core\Request;
use App\Modules\Pages\PageLinks;
use App\Support\SafeUrl;

/**
 * The owner's own WORDS in the header and footer, once per language (PLAN.md D-028, D-059):
 * the button and where it leads, the footer's text, the small print.
 *
 * Its own class because it now has two readers — the Appearance screen edits these, and the
 * preview draws them before they are saved — and because the rest of the chrome is CHOICES
 * from closed sets while this is free text that needs checking. ChromeLook is the choices;
 * this is the words.
 *
 * FORM FIELD NAMES ARE NOT SETTINGS KEYS. The field is `header_button_label_hr`; the key it
 * ends up under is SiteChrome's business alone, and chrome_test.php fails if a `chrome_`
 * literal appears outside that class. Both namespaces once shared a prefix and neither a
 * reader nor the guard could tell a posted field from a stored key.
 */
final class ChromeWords
{
    /** What the owner writes, per language; posted as <field>_<locale>. */
    private const FIELDS = [
        'button_label' => 'header_button_label',
        'button_url' => 'header_button_url',
        'button_page' => 'header_button_page',
        'text' => 'footer_text',
        'small_print' => 'footer_small_print',
    ];

    /**
     * The posted name of one field. The view and the controller must agree on it exactly,
     * so it is composed here rather than spelled out in both.
     */
    public static function field(string $name, string $locale): string
    {
        return (self::FIELDS[$name] ?? $name) . '_' . $locale;
    }

    /**
     * What is stored, for every language the site has.
     *
     * @param list<string> $locales
     * @return array<string, array{button_label: string, button_url: string, text: string, small_print: string}>
     */
    public static function stored(Db $db, array $locales): array
    {
        $words = [];
        foreach ($locales as $code) {
            $header = SiteChrome::header($db, $code);
            $footer = SiteChrome::footer($db, $code);
            $words[$code] = [
                'button_label' => $header['button']['label'],
                'button_url' => $header['button']['url'],
                'text' => $footer['text'],
                'small_print' => $footer['small_print'],
            ];
        }

        return $words;
    }

    /**
     * What a save is trying, checked. Errors are keyed by the FIELD that carries them, so
     * the screen can put each message beside its own input.
     *
     * @param list<string> $locales
     * @return array{values: array<string, array{button_label: string, button_url: string, text: string, small_print: string}>, errors: array<string, string>}
     */
    public static function fromRequest(Request $request, array $locales): array
    {
        $values = [];
        $errors = [];
        foreach ($locales as $code) {
            // Written out rather than built in a loop: saveForLocale() takes all four keys,
            // and a loop only ever establishes "these keys might be present", which is a
            // promise asserted at the boundary and proved nowhere.
            $entry = [
                'button_label' => trim($request->input(self::field('button_label', $code))),
                'button_url' => SafeUrl::normalize($request->input(self::field('button_url', $code))),
                'text' => trim($request->input(self::field('text', $code))),
                'small_print' => trim($request->input(self::field('small_print', $code))),
            ];
            // A chosen page wins over a typed address, as in a block's link field (D-034);
            // the address input is hidden while a page is chosen.
            $page = trim($request->input(self::field('button_page', $code)));
            if (preg_match('~^[1-9][0-9]{0,9}$~', $page) === 1) {
                $entry['button_url'] = PageLinks::to((int) $page);
            }
            // The same guard the menu builder uses for an item's address: an address we
            // would not follow is refused here rather than written into every page's header.
            if ($entry['button_url'] !== '' && !SafeUrl::isLink($entry['button_url'])) {
                $errors[self::field('button_url', $code)] = t('chrome.button_url_refused');
            }
            $values[$code] = $entry;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param array<string, array{button_label: string, button_url: string, text: string, small_print: string}> $values
     */
    public static function save(Db $db, array $values): void
    {
        foreach ($values as $code => $entry) {
            SiteChrome::saveForLocale($db, $code, $entry);
        }
    }
}
