<?php

namespace App\Services;

use App\Models\News;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Saves news of the platform (club null) or of a club, with an optional picture. */
class NewsManager
{
    /** @return array<string, mixed> */
    public function validate(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'is_published' => ['sometimes', 'boolean'],
            // PNG and JPEG: shown on the PC as they are, no extra codecs.
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096'],
            'remove_image' => ['sometimes', 'boolean'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ?int $clubId, User $author, callable $log): News
    {
        $image = $this->storeImage($data['image'] ?? null, $clubId);

        return DB::transaction(function () use ($data, $clubId, $author, $image, $log): News {
            $news = new News(['title' => $data['title'], 'body' => $data['body'], 'is_published' => (bool) ($data['is_published'] ?? true)]);
            $news->forceFill(['club_id' => $clubId, 'created_by' => $author->getKey(), 'image_path' => $image])->save();
            $log($news);

            return $news;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(News $news, array $data, callable $log): void
    {
        $image = $this->storeImage($data['image'] ?? null, $news->club_id);
        $previous = $news->image_path;
        $dropImage = $image !== null || (bool) ($data['remove_image'] ?? false);

        DB::transaction(function () use ($news, $data, $image, $dropImage, $log): void {
            $news->fill(['title' => $data['title'], 'body' => $data['body'], 'is_published' => (bool) ($data['is_published'] ?? false)]);
            if ($dropImage) {
                $news->forceFill(['image_path' => $image]);
            }
            $news->save();
            $log($news);
        });

        if ($dropImage && $previous !== null) {
            Storage::disk('local')->delete($previous);
        }
    }

    public function delete(News $news, callable $log): void
    {
        DB::transaction(function () use ($news, $log): void {
            $news->delete();
            $log($news);
        });

        if ($news->image_path !== null) {
            Storage::disk('local')->delete($news->image_path);
        }
    }

    /** Random file name: the picture is public by its URL, like the club logo. */
    private function storeImage(?UploadedFile $file, ?int $clubId): ?string
    {
        if ($file === null) {
            return null;
        }

        $extension = $file->guessExtension() === 'png' ? 'png' : 'jpg';

        return $file->storeAs('news/'.($clubId ?? 'platform'), Str::random(40).'.'.$extension, 'local');
    }
}
