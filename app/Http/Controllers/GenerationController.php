<?php

namespace App\Http\Controllers;

use App\Exceptions\GenerationLimitException;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\StoreGenerationRequest;
use App\Pixelforge\PixelforgeClient;
use App\Services\GenerationService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly GenerationService $generations) {}

    public function index(Request $request, PixelforgeClient $client): Response
    {
        $pricing = $client->pricing();
        $generations = $request->user()->generations()->latest('id')->paginate(12);

        return Inertia::render('Generations/Index', [
            'options' => [
                'sizes' => $pricing->sizes(),
                'qualities' => $pricing->qualities(),
                'maxReferenceImages' => 4,
                'maxPromptLength' => 4000,
                'markupPercent' => (int) config('pixelforge.markup_percent'),
            ],
            'generations' => $generations->through(fn ($g) => $g->toFrontend()),
            'hasInFlight' => $request->user()->generations()->inFlight()->exists(),
        ]);
    }

    /** Live price preview while the user edits the form. */
    public function estimate(Request $request, PixelforgeClient $client): JsonResponse
    {
        $pricing = $client->pricing();
        $data = $request->validate([
            'characters' => ['required', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'references' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        $estimate = $this->generations->estimate($data['characters'], $data['size'], $data['quality'], $data['references']);

        return response()->json([
            ...$estimate->toArray(),
            'available' => Money::nanoToDecimal($request->user()->availableNano()),
            'affordable' => $request->user()->availableNano() >= $estimate->maxChargeNano,
        ]);
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        try {
            $this->generations->create($request->user(), $request->validated());
        } catch (GenerationLimitException $e) {
            return back()->withErrors(['limit' => $e->getMessage()]);
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors([
                'credits' => sprintf(
                    'This generation can cost up to $%s but you have $%s available. Buy credits to continue.',
                    Money::nanoToDecimal($e->requiredNano), Money::nanoToDecimal($e->availableNano),
                ),
            ]);
        }

        return to_route('generations.index')->with('success', 'Generation submitted.');
    }
}
