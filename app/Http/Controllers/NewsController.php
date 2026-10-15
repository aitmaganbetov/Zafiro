<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\ActivityLogger;
use App\Services\CurrentClub;
use App\Services\NewsManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Club news for the PC agent (second column of the agent's home screen). */
class NewsController extends Controller
{
    public function __construct(private NewsManager $news, private CurrentClub $currentClub, private ActivityLogger $activity) {}

    public function index(): View
    {
        return view('news.index', [
            'items' => News::query()->ofClub($this->currentClub->id())->latest()->latest('id')->paginate(News::PAGE),
            'routes' => ['store' => 'news.store', 'update' => 'news.update', 'destroy' => 'news.destroy'],
            'layout' => 'layouts.admin',
            'intro' => __('Новости клуба показываются на главном экране агента на ПК клуба (вторая карточка).'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->news->create($this->news->validate($request), $this->currentClub->id(), $request->user(), fn (News $news) => $this->log('news.created', __('Добавлена новость: :title', ['title' => $news->title]), $request, $news));

        return back()->with('status', __('Новость добавлена.'));
    }

    public function update(Request $request, News $news): RedirectResponse
    {
        $this->ensureOwn($news);
        $this->news->update($news, $this->news->validate($request), fn (News $news) => $this->log('news.updated', __('Изменена новость: :title', ['title' => $news->title]), $request, $news));

        return back()->with('status', __('Новость сохранена.'));
    }

    public function destroy(Request $request, News $news): RedirectResponse
    {
        $this->ensureOwn($news);
        $this->news->delete($news, fn (News $news) => $this->log('news.deleted', __('Удалена новость: :title', ['title' => $news->title]), $request, null));

        return back()->with('status', __('Новость удалена.'));
    }

    /** Pictures are public by their random file name (the agent loads them without a token). */
    public function image(News $news, string $file): StreamedResponse
    {
        abort_unless($news->image_path !== null && basename($news->image_path) === $file, 404);
        abort_unless(Storage::disk('local')->exists($news->image_path), 404);

        return Storage::disk('local')->response($news->image_path, $file, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    private function ensureOwn(News $news): void
    {
        abort_unless($news->club_id !== null && $news->club_id === $this->currentClub->id(), 404);
    }

    private function log(string $event, string $description, Request $request, ?News $news): void
    {
        $this->activity->record($event, $description, $request->user()->getKey(), $news);
    }
}
