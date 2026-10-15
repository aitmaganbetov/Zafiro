<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Services\NewsManager;
use App\Services\PlatformLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Platform news shown in every club (first column of the agent's home screen). */
class NewsController extends Controller
{
    public function __construct(private NewsManager $news, private PlatformLogger $platformLog) {}

    public function index(): View
    {
        return view('news.index', [
            'items' => News::query()->platform()->latest()->latest('id')->paginate(News::PAGE),
            'routes' => ['store' => 'superadmin.news.store', 'update' => 'superadmin.news.update', 'destroy' => 'superadmin.news.destroy'],
            'layout' => 'layouts.superadmin',
            'intro' => __('Новости Zafiro показываются на главном экране агента во всех клубах (первая карточка).'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->news->create($this->news->validate($request), null, $request->user(), fn (News $news) => $this->platformLog->record('superadmin.news_created', __('Добавлена новость: :title', ['title' => $news->title]), $request->user(), null, $news));

        return back()->with('status', __('Новость добавлена.'));
    }

    public function update(Request $request, News $news): RedirectResponse
    {
        abort_unless($news->club_id === null, 404);
        $this->news->update($news, $this->news->validate($request), fn (News $news) => $this->platformLog->record('superadmin.news_updated', __('Изменена новость: :title', ['title' => $news->title]), $request->user(), null, $news));

        return back()->with('status', __('Новость сохранена.'));
    }

    public function destroy(Request $request, News $news): RedirectResponse
    {
        abort_unless($news->club_id === null, 404);
        $this->news->delete($news, fn (News $news) => $this->platformLog->record('superadmin.news_deleted', __('Удалена новость: :title', ['title' => $news->title]), $request->user()));

        return back()->with('status', __('Новость удалена.'));
    }
}
