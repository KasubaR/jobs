<?php

namespace App\Http\Controllers;

use App\Extraction\ExtractionPreview;
use App\Import\AdFileParser;
use App\Import\AdImporter;
use App\Import\ImportFileException;
use Illuminate\Http\Response;
use App\Models\Source;
use App\Services\SourceManager;
use App\Support\SourceFormData;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

/** The logged, deliberate changes: access decisions, switching on/off, testing, running, adding ads. */
class SourceActionController extends Controller
{
    public function __construct(private SourceManager $manager) {}

    public function decideAccess(Request $request, Source $source): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,approved,restricted'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $this->manager->decideAccess($source, $data['status'], $data['note']);

        return back()->with('status', "Access for {$source->name} set to {$data['status']}.");
    }

    public function activate(Source $source): RedirectResponse
    {
        try {
            $this->manager->activate($source);
        } catch (DomainException $e) {
            return back()->with('error', "{$source->name} was not activated. {$e->getMessage()}");
        }

        return back()->with('status', "{$source->name} is now active.");
    }

    public function deactivate(Source $source): RedirectResponse
    {
        $this->manager->deactivate($source);

        return back()->with('status', "{$source->name} is now inactive.");
    }

    public function test(Source $source): RedirectResponse
    {
        try {
            $result = $this->manager->testAccess($source);
        } catch (RuntimeException $e) {
            return back()->with('warning', 'Cannot test: '.$e->getMessage());
        }

        $message = "{$result->message} ({$result->durationMs} ms"
            .($result->httpStatus ? ", HTTP {$result->httpStatus}" : '')
            .($result->ok ? ", {$result->itemCount} ads found" : '').')';

        return back()->with($result->ok ? 'status' : 'error', ($result->ok ? 'Reachable. ' : 'Not accessible. ').$message);
    }

    public function run(Source $source): RedirectResponse
    {
        try {
            $run = $this->manager->runNow($source);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Normally the run happens in the background worker: say so, and point to the run list.
        if (! $run) {
            return back()->with('status', "{$source->name} is queued. It starts within about a minute; the result will appear under Collection runs. If it was already waiting or running, nothing new is added.");
        }

        $summary = "{$source->name}: {$run->status}. Discovered {$run->items_found}, new {$run->items_new}, changed {$run->items_updated}, unchanged {$run->items_unchanged}, failed {$run->items_failed}".($run->error_message ? ". {$run->error_message}" : '').'.';

        return back()->with($run->status === 'failed' ? 'error' : 'status', $summary);
    }

    public function addAd(Request $request, Source $source): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'url' => ['nullable', 'url:http,https', 'max:500'],
            'content' => ['required', 'string', 'max:100000'],
        ]);

        try {
            $run = $this->manager->submitAd($source, $data);
        } catch (DomainException|RuntimeException $e) {
            return back()->withInput()->with('error', 'Ad not added. '.$e->getMessage());
        }

        if ($run->status === 'failed') {
            return back()->withInput()->with('error', 'Ad not added. '.$run->error_message);
        }

        return back()->with('status', $run->items_new + $run->items_updated > 0 ? 'Ad added.' : 'That ad is already stored, so nothing new was added.');
    }

    /** A file of ads (CSV or JSON), stored as one run. */
    public function importFile(Request $request, Source $source, AdImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (config('job_intel.import_max_bytes') / 1024)],
        ]);

        $file = $request->file('file');
        $format = AdFileParser::formatFor($file->getClientOriginalName());

        if (! $format) {
            return back()->with('error', 'Only .csv and .json files can be imported.');
        }

        try {
            $run = $importer->import($source, (string) file_get_contents($file->getRealPath()), $format);
        } catch (ImportFileException|RuntimeException $e) {
            return back()->with('error', 'Import failed. '.$e->getMessage());
        }

        $summary = "{$run->status}: discovered {$run->items_found}, new {$run->items_new}, changed {$run->items_updated}, unchanged {$run->items_unchanged}, failed {$run->items_failed}.";

        return back()
            ->with($run->status === 'failed' ? 'error' : 'status', ($run->status === 'failed' ? 'Nothing was imported. ' : 'Import '.($run->status === 'partial' ? 'partly done, ' : 'done, ')).$summary.($run->error_message ? " {$run->error_message}" : ''))
            ->with('run_id', $run->id);
    }

    /** A small CSV showing the columns, with an example row. Opens in Excel as UTF-8. */
    public function importSample(Source $source): Response
    {
        $csv = "\xEF\xBB\xBF"
            ."title,url,content,external_id,structured\r\n"
            .'"Primary School Teacher at Example Council",,"Applications are invited from qualified teachers.'."\n"
            .'Closing date: 30 April 2027.",EC-2027-001,"{""employer"": ""Example Council"", ""locations"": [{""locality"": ""Kitwe""}]}"'."\r\n"
            .'"Driver at Example Mining","https://example.com/jobs/driver","Drive haul trucks on site.",,'."\r\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="zamtrends-ads-sample.csv"',
        ]);
    }

    /** Try the extraction rules as typed in the form on stored ads. Reads the database only. */
    public function previewExtraction(Request $request, Source $source, ExtractionPreview $preview): View
    {
        return view('sources.extraction-preview', [
            'preview' => $preview->run($source, SourceFormData::extraction($request->all())),
        ]);
    }
}
