<?php

namespace App\Duplicates;

use App\Models\Job;
use App\Standardization\MatchingKeys;
use App\Standardization\TermDictionary;

/**
 * How likely two jobs are the same vacancy, from the matching keys. Pure: it reads two jobs and writes nothing.
 *
 * Two certain cases come first: the same specific application link, or the same ad text from the same employer
 * (each only when the titles also agree, because a generic "careers" link or a shared template says nothing about
 * which job it is). Everything else is a score of 0 to 100 from employer, title, place, deadline, posting date,
 * application link and text, with weights in config/job_intel.php.
 *
 * Guards that stop a wrong merge (each is recorded in the breakdown):
 *   - different places                      the same title in two towns is two openings, unless the text is the same
 *   - posting dates far apart               a re-advertisement or a new opening
 *   - closing dates far apart               the same
 *   - an agency named as the employer       the ad may not be theirs: never merged automatically
 *   - both ads from the same source         the same site listing a title twice is more often two openings: never automatic
 */
class DuplicateScorer
{
    public function __construct(private TermDictionary $terms) {}

    /**
     * @return array{score:int, reason:string, exact:bool, no_auto:bool, breakdown:array<string, mixed>}
     */
    public function score(Job $a, Job $b): array
    {
        $cfg = config('job_intel.duplicates');
        $w = $cfg['weights'];
        $notes = [];
        $cap = null;
        $noAuto = false;

        $titleSim = self::similarity($a->match_title, $b->match_title);
        $distance = ($a->content_fingerprint && $b->content_fingerprint) ? MatchingKeys::distance($a->content_fingerprint, $b->content_fingerprint) : null;
        $identical = $distance !== null && $distance <= $cfg['identical_text_distance'];
        $sameEmployer = $a->match_employer !== null && $a->match_employer === $b->match_employer;

        $sameUrl = $a->match_url !== null && $a->match_url === $b->match_url;
        $sameText = $a->content_hash_norm !== null && $a->content_hash_norm === $b->content_hash_norm && $sameEmployer;

        $parts = [];

        // employer
        $agency = preg_match($cfg['agency_pattern'], (string) $a->match_employer) || preg_match($cfg['agency_pattern'], (string) $b->match_employer);
        if ($agency) {
            $parts['employer'] = 0;
            $noAuto = true;
            $notes[] = 'An agency is named as the employer, so the employer proves nothing; never merged automatically.';
        } else {
            $parts['employer'] = $sameEmployer ? $w['employer'] : 0;
        }

        // title
        $parts['title'] = (int) round($w['title'] * $titleSim);

        // place
        [$parts['location'], $placeConflict] = $this->place($a, $b, $w['location']);
        if ($placeConflict && ! $identical) {
            $cap = 69;
            $notes[] = 'Different places, and the text is not the same: treated as separate openings.';
        }

        // closing date
        $parts['deadline'] = $w['deadline'] / 2; // not known on one side: half the points, no verdict
        if ($a->closing_date && $b->closing_date) {
            $days = abs($a->closing_date->diffInDays($b->closing_date));
            $parts['deadline'] = $days === 0 ? $w['deadline'] : ($days <= 3 ? $w['deadline'] * 0.6 : ($days > $cfg['closing_conflict_days'] ? 0 : $w['deadline'] * 0.2));
            if ($days > $cfg['closing_conflict_days']) {
                $cap = min($cap ?? 100, 69);
                $notes[] = "Closing dates are {$days} days apart: a new opening, not a copy.";
            }
        }

        // posting date
        $parts['posted'] = 0;
        if ($a->match_date && $b->match_date) {
            $days = abs($a->match_date->diffInDays($b->match_date));
            $parts['posted'] = $days <= 3 ? $w['posted'] : ($days <= 14 ? $w['posted'] * 0.6 : ($days <= $cfg['window_days'] ? $w['posted'] * 0.2 : 0));
            if ($days > $cfg['window_days']) {
                $cap = min($cap ?? 100, 69);
                $notes[] = "Posted {$days} days apart: a re-advertisement or a new opening.";
            }
        }

        // application link
        $parts['url'] = $sameUrl ? $w['url'] : 0;

        // text
        $parts['text'] = $distance === null ? 0 : ($distance <= $cfg['identical_text_distance'] ? $w['text'] : ($distance <= 8 ? $w['text'] * 0.7 : ($distance <= 12 ? $w['text'] * 0.4 : ($distance <= 16 ? $w['text'] * 0.15 : 0))));
        if ($distance === null) {
            $notes[] = 'No text fingerprint on one side, so the text could not be compared.';
        }

        if ($a->sourceId() === $b->sourceId() && $a->sourceId() !== null) {
            $noAuto = true;
            $notes[] = 'Both ads come from the same source: never merged automatically.';
        }

        $score = (int) round(array_sum($parts));
        if ($cap !== null) {
            $score = min($score, $cap);
        }

        $exact = false;
        $reason = 'score';
        if ($titleSim >= 0.5 && $cap === null && ! $agency) {
            if ($sameUrl) {
                [$exact, $reason, $score] = [true, 'exact_url', 100];
            } elseif ($sameText) {
                [$exact, $reason, $score] = [true, 'exact_text', 100];
            }
        }

        if ($exact && $a->sourceId() === $b->sourceId() && $a->sourceId() !== null) {
            $exact = false; // one site listing the same text twice is for a person to look at
            $reason = 'score';
            $score = min($score, $cfg['auto'] - 1);
        }

        return [
            'score' => $score,
            'reason' => $reason,
            'exact' => $exact,
            'no_auto' => $noAuto,
            'breakdown' => ['parts' => array_map(fn ($p) => round($p, 1), $parts), 'title_similarity' => round($titleSim, 2), 'text_distance' => $distance, 'notes' => $notes, 'cap' => $cap],
        ];
    }

    /** Share of words two normalized titles have in common (0 to 1). */
    public static function similarity(?string $a, ?string $b): float
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $x = array_unique(explode(' ', $a));
        $y = array_unique(explode(' ', $b));
        $union = count(array_unique([...$x, ...$y]));

        return $union === 0 ? 0.0 : count(array_intersect($x, $y)) / $union;
    }

    /** @return array{0: float|int, 1: bool} points and whether the two ads are in different places */
    private function place(Job $a, Job $b, int $weight): array
    {
        if ($a->location_id && $b->location_id) {
            if ($a->location_id === $b->location_id) {
                return [$weight, false];
            }

            $parents = [];
            foreach ($this->terms->places() as $p) {
                $parents[$p->id] = $p->parent_id;
            }

            // a town and its own province are the same place described at two levels
            if (($parents[$a->location_id] ?? null) === $b->location_id || ($parents[$b->location_id] ?? null) === $a->location_id) {
                return [$weight * 0.7, false];
            }

            return [0, true];
        }

        if ($a->match_location !== null && $a->match_location === $b->match_location) {
            return [$weight, false];
        }

        return [$weight / 2, false]; // one side unknown: half the points, no verdict
    }
}
