<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Enums\GenerationStatus;
use App\Exceptions\InsufficientCredits;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PricingCatalogRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationController
{
    /**
     * History: the user's generations, newest first.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $generations = $user->generations()->with('cost')->latest('id')->paginate(12);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            // The page polls for updates only while something is unfinished.
            'hasInFlight' => $user->generations()->whereIn('status', GenerationStatus::inFlight())->exists(),
        ]);
    }

    /**
     * The Generate page. Sizes and qualities come from Pixelforge's live
     * price list.
     */
    public function create(PricingCatalogRepository $catalogs): Response
    {
        try {
            $catalog = $catalogs->current();
            $options = ['sizes' => $catalog->sizes(), 'qualities' => $catalog->qualities()];
        } catch (PixelforgeException) {
            $options = null;
        }

        return Inertia::render('generations/Create', [
            'options' => $options,
            'limits' => [
                'maxPromptLength' => config()->integer('pixelforge.max_prompt_length'),
                'maxReferenceImages' => config()->integer('pixelforge.max_reference_images'),
            ],
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $createGeneration->handle($user, $request->generationInput());
        } catch (InsufficientCredits $exception) {
            throw ValidationException::withMessages(['credits' => $exception->getMessage()]);
        } catch (PixelforgeException) {
            throw ValidationException::withMessages([
                'pricing' => 'Live pricing is temporarily unavailable, so this generation can\'t be priced. Please try again in a moment.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Generation queued. You\'ll see it update here.']);

        return to_route('generations.index');
    }
}
