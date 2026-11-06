<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Collectors\CollectorRegistry;
use App\Models\Source;
use App\Collectors\Html\HtmlConfig;
use App\Extraction\ExtractionRules;
use App\Extraction\Extractor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SourceForm
{
    private static function selectorRule(): \Closure
    {
        return fn () => function (string $attribute, mixed $value, \Closure $fail) {
            if (filled($value) && ($problem = HtmlConfig::selectorProblem((string) $value))) {
                $fail("This selector {$problem}");
            }
        };
    }

    public static function configure(Schema $schema): Schema
    {
        $collectors = collect(array_keys(CollectorRegistry::COLLECTORS))
            ->mapWithKeys(fn (string $key) => [$key => $key])
            ->all();

        return $schema
            ->components([
                Section::make('Source')
                    ->description('Access status and the on/off switch are not edited here. Use "Decide access" and "Activate" on the source list so every change is recorded.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(160),
                        Select::make('source_type')
                            ->options(Source::SOURCE_TYPES)
                            ->required(),
                        TextInput::make('website_url')
                            ->label('Website URL')
                            ->required()
                            ->url()
                            ->rule('url:http,https')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('job_listing_url')
                            ->label('Job listing URL')
                            ->helperText('The feed or page where vacancies appear.')
                            ->url()
                            ->rule('url:http,https')
                            ->nullable()
                            ->maxLength(255),
                    ]),

                Section::make('Collection')
                    ->columns(2)
                    ->schema([
                        Select::make('collection_method')
                            ->options(Source::METHODS)
                            ->required()
                            ->default('pending_review'),
                        Select::make('collector')
                            ->live()
                            ->options($collectors)
                            ->placeholder('None (cannot be collected yet)')
                            ->helperText('The code that knows this website. A new website needs a collector written for it.')
                            ->nullable(),
                        Select::make('collection_frequency')
                            ->options(Source::FREQUENCIES)
                            ->required()
                            ->default('daily'),
                        TextInput::make('min_interval_seconds')
                            ->label('Minimum seconds between requests')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(3600)
                            ->default(1)
                            ->required()
                            ->helperText('Never lower than the site\'s own Crawl-delay.'),
                        Toggle::make('retain_raw')
                            ->label('Keep raw ad text (90 days)')
                            ->helperText('Turn off if the site refuses retention. Only extracted fields are then kept.')
                            ->default(true),
                    ]),

                Section::make('Public HTML pages')
                    ->description('How to find the ads on this website. CSS selectors only, no code. Nothing is collected until the source is approved and active.')
                    ->visible(fn (Get $get) => $get('collector') === 'html_listing')
                    ->columns(2)
                    ->schema([
                        TextInput::make('collector_config.html.item')
                            ->label('Ad on the listing page (selector)')
                            ->helperText('One match per ad, e.g. li.job-card')
                            ->required(fn (Get $get) => $get('collector') === 'html_listing')
                            ->rule(self::selectorRule()),
                        TextInput::make('collector_config.html.link')
                            ->label('Link inside the ad (selector)')
                            ->placeholder('a')
                            ->rule(self::selectorRule()),
                        TextInput::make('collector_config.html.title')
                            ->label('Title inside the ad (selector)')
                            ->helperText('Empty = use the link text.')
                            ->rule(self::selectorRule()),
                        TextInput::make('collector_config.html.next')
                            ->label('"Next page" link (selector)')
                            ->rule(self::selectorRule()),
                        TextInput::make('collector_config.html.content')
                            ->label('Full ad text on the ad page (selector)')
                            ->rule(self::selectorRule()),
                        Toggle::make('collector_config.html.fetch_detail')
                            ->label('Open each new ad\'s own page')
                            ->helperText('One extra request per new ad, up to the limit below.'),
                        TextInput::make('collector_config.html.max_pages')
                            ->label('Listing pages per run')
                            ->numeric()->integer()->minValue(1)->maxValue(HtmlConfig::MAX_PAGES)->default(1),
                        TextInput::make('collector_config.html.max_items')
                            ->label('Ads per run')
                            ->numeric()->integer()->minValue(1)->maxValue(HtmlConfig::MAX_ITEMS)->default(50),
                        TextInput::make('collector_config.html.max_detail_requests')
                            ->label('Ad pages opened per run')
                            ->numeric()->integer()->minValue(0)->maxValue(HtmlConfig::MAX_DETAIL_REQUESTS)->default(20),
                    ]),

                Section::make('Extraction rules')
                    ->description('How to read this website\'s ads. Rules are plain patterns, not code. Use "Preview extraction" on the edit page to try them on stored ads before relying on them.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('collector_config.extraction.title_separator')
                            ->label('Split the title into job and employer on')
                            ->placeholder(' at ')
                            ->helperText('"Budget Analyst at World Vision" splits on " at ". Uses the last occurrence.')
                            ->maxLength(20),
                        TagsInput::make('collector_config.extraction.date_formats')
                            ->label('Date formats on this site')
                            ->placeholder('e.g. j F Y')
                            ->helperText('PHP formats: "j F Y" reads 5 December 2026, "d/m/Y" reads 05/12/2026.'),
                        Repeater::make('collector_config.extraction.fields')
                            ->label('Patterns')
                            ->helperText('The first (bracketed) group in the pattern becomes the value. Example: closing date:\s*(.+)')
                            ->schema([
                                Select::make('field')
                                    ->options(Extractor::FIELDS)
                                    ->required(),
                                TextInput::make('pattern')
                                    ->required()
                                    ->maxLength(ExtractionRules::MAX_PATTERN_LENGTH)
                                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                        if ($problem = ExtractionRules::patternProblem((string) $value)) {
                                            $fail($problem);
                                        }
                                    }),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add pattern')
                            ->columnSpanFull(),
                    ]),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
