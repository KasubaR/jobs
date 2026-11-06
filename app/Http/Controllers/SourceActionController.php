<?php

namespace App\Http\Controllers;

use App\Extraction\ExtractionPreview;
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
        if (! $source->is_active || $source->access_status !== 'approved' || $source->collection_method === 'manual') {
            return back()->with('error', 'Only an approved, active, automatic source can be run.');
        }

        try {
            $run = $this->manager->runNow($source);
        } catch (RuntimeException $e) {
            return back()->with('error', 'Cannot run: '.$e->getMessage());
        }

        $summary = "{$source->name}: {$run->status}. Found {$run->items_found}, new {$run->items_new}, updated {$run->items_updated}".($run->error_message ? ". {$run->error_message}" : '').'.';

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

        return back()->with('status', $run->items_new > 0 ? 'Ad added.' : 'That ad is already stored, so nothing new was added.');
    }

    /** Try the extraction rules as typed in the form on stored ads. Reads the database only. */
    public function previewExtraction(Request $request, Source $source, ExtractionPreview $preview): View
    {
        return view('sources.extraction-preview', [
            'preview' => $preview->run($source, SourceFormData::extraction($request->all())),
        ]);
    }
}
