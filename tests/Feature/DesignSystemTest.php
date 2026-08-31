<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The design system, enforced rather than merely documented.
 *
 * Everything in CLAUDE.md's Layout conventions was previously kept true
 * by review alone, so nothing stopped a new staff page writing its own
 * button, its own container width, or its own colour for a status that
 * already had one — which is exactly how the app drifted into six
 * different status palettes and four different page widths in the first
 * place.
 *
 * These are lint rules, not behaviour tests. Each failure names the file
 * and says what to use instead, and each rule is narrow enough that a
 * deliberate exception is a small, visible edit here.
 */
class DesignSystemTest extends TestCase
{
    /** Staff pages only — the public site is a separate visual world. */
    private const PUBLIC_PATHS = ['/Pages/Public/', '/Pages/Auth/'];

    /** @return array<string, array{0: string}> */
    public static function staffPageProvider(): array
    {
        // dirname(), not base_path(): a static data provider runs before
        // the application container exists.
        $root = str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 2));

        $files = array_merge(
            glob("{$root}/resources/js/Pages/*.jsx") ?: [],
            glob("{$root}/resources/js/Pages/*/*.jsx") ?: [],
            glob("{$root}/resources/js/Pages/*/*/*.jsx") ?: [],
        );

        $cases = [];

        foreach ($files as $file) {
            $normalised = str_replace(DIRECTORY_SEPARATOR, '/', $file);

            foreach (self::PUBLIC_PATHS as $skip) {
                if (str_contains($normalised, $skip)) {
                    continue 2;
                }
            }

            $cases[str_replace($root.'/', '', $normalised)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('staffPageProvider')]
    public function test_a_staff_page_does_not_hand_roll_a_dialog(string $file): void
    {
        $source = file_get_contents($file);

        $this->assertStringNotContainsString(
            'fixed inset-0',
            $source,
            basename($file).': use Components/UI/Modal rather than a hand-rolled overlay — '
                .'a bare div has no focus trap, no Escape, and no scroll lock.',
        );
    }

    #[DataProvider('staffPageProvider')]
    public function test_a_staff_page_does_not_use_a_native_confirm(string $file): void
    {
        $source = file_get_contents($file);

        $this->assertDoesNotMatchRegularExpression(
            '/(?<![\w.])confirm\s*\(/',
            $source,
            basename($file).': use Components/UI/ConfirmDialog rather than window.confirm(), '
                .'which cannot say what the consequence of the action is.',
        );
    }

    #[DataProvider('staffPageProvider')]
    public function test_a_staff_page_sets_its_own_container_width(string $file): void
    {
        $source = file_get_contents($file);

        // PageContainer owns the one width and the one padding rule. A page
        // that sets `max-w-*` on its own scroll container is how the app
        // ended up with four different page widths, and how every page
        // ended up with no horizontal padding on a phone.
        preg_match_all('/className="[^"]*\bmx-auto\b[^"]*\bmax-w-(?!shell)[^"\s]+/', $source, $matches);

        $this->assertSame(
            [],
            $matches[0],
            basename($file).': use PageContainer rather than setting a page width — found '
                .implode(', ', $matches[0]),
        );
    }

    #[DataProvider('staffPageProvider')]
    public function test_a_staff_page_does_not_invent_a_status_colour(string $file): void
    {
        $source = file_get_contents($file);

        // A per-page map of status => Tailwind classes. statuses.js is the
        // one place a status gets a colour; six pages used to disagree
        // about what `scheduled` looked like.
        $banned = [
            'STATUS_BADGE',
            'CONDITION_COLORS',
            'PRIORITY_COLORS',
            'TYPE_BADGE',
        ];

        foreach ($banned as $name) {
            $this->assertStringNotContainsString(
                "const {$name}",
                $source,
                basename($file).": map statuses through Components/UI/statuses.js rather than a local {$name}.",
            );
        }
    }

    #[DataProvider('staffPageProvider')]
    public function test_a_staff_page_labels_its_inputs(string $file): void
    {
        $source = file_get_contents($file);

        // A label has to name something. Either it carries htmlFor, or it
        // wraps its own control — both are valid; a <label> that does
        // neither is decoration, which is what left ~60 inputs in this app
        // with no accessible name.
        preg_match_all('/<label\b(.*?)<\/label>/s', $source, $matches);

        $unnamed = array_values(array_filter(
            $matches[0],
            fn (string $label) => ! str_contains($label, 'htmlFor')
                && ! preg_match('/<(?:input|select|textarea)\b/', $label),
        ));

        $this->assertSame(
            [],
            $unnamed,
            basename($file).': use Field / SelectField / TextareaField, or wrap the control — '
                .'a <label> with no htmlFor and no control inside it names nothing.',
        );
    }

    #[DataProvider('staffPageProvider')]
    public function test_a_staff_page_uses_the_apps_neutral_palette(string $file): void
    {
        $source = file_get_contents($file);

        // `gray` is Tailwind's default and the scaffold's; the staff app is
        // `slate`. Mixing them is invisible in isolation and obvious side
        // by side.
        preg_match_all('/\b(?:bg|text|border|ring|divide)-gray-\d{2,3}\b/', $source, $matches);

        $this->assertSame(
            [],
            array_unique($matches[0]),
            basename($file).': the staff app uses slate, not gray — found '
                .implode(', ', array_unique($matches[0])),
        );
    }

    /**
     * The public site keeps its own warm palette; the staff app must not
     * borrow it, and vice versa. This is the CLAUDE.md hard constraint
     * that the two visual worlds stay separate.
     */
    #[DataProvider('staffPageProvider')]
    public function test_a_staff_page_does_not_borrow_the_public_palette(string $file): void
    {
        $source = file_get_contents($file);

        preg_match_all('/\b(?:bg|text|border|ring)-(?:stone|teal)-\d{2,3}\b/', $source, $matches);
        $found = array_unique($matches[0]);

        // `teal` survives as one tooth condition on the chart, which is a
        // clinical colour rather than the public site's brand.
        $found = array_values(array_filter($found, fn ($class) => ! str_contains($file, 'DentalChart')));

        $this->assertSame(
            [],
            $found,
            basename($file).": the public site's stone/teal palette must not leak into the staff app — found "
                .implode(', ', $found),
        );
    }

    public function test_the_shared_ui_components_are_where_the_conventions_say(): void
    {
        foreach ([
            'Button', 'Card', 'Field', 'Modal', 'Page', 'Pagination',
            'StatusBadge', 'Tabs', 'Toast', 'ClinicMark',
        ] as $component) {
            $this->assertFileExists(
                base_path("resources/js/Components/UI/{$component}.jsx"),
                "Components/UI/{$component}.jsx is named in CLAUDE.md's Layout conventions.",
            );
        }

        $this->assertFileExists(base_path('resources/js/Components/UI/statuses.js'));
    }
}
