<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Models\Media;
use App\Modules\Marketing\Models\SocialPost;
use App\Modules\Marketing\Services\Social\SocialImageRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** Descărcări pentru postări: imagini în formatele rețelelor și pachetul complet (imagini + text). */
class SocialMediaController extends Controller
{
    public function image(Request $request, Media $media, string $format, SocialImageRenderer $renderer): Response
    {
        abort_unless($request->user()?->can('social.manage'), 403);
        abort_unless($media->isImage() && $media->isPublishable(), 404);

        return response($renderer->render($media, $format), 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => ($request->boolean('preview') ? 'inline' : 'attachment').'; filename="'.$renderer->filename($media, $format).'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function bundle(Request $request, SocialPost $post, SocialImageRenderer $renderer): BinaryFileResponse
    {
        abort_unless($request->user()?->can('social.manage'), 403);

        $format = $post->format === 'story' || $post->format === 'reel' ? 'story' : 'portrait';
        $path = tempnam(sys_get_temp_dir(), 'aw-social');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('text.txt', $post->fullText()."\n");
        foreach ($post->media()->filter(fn (Media $m) => $m->isPublishable())->values() as $index => $media) {
            $zip->addFromString(sprintf('%02d-%s', $index + 1, $renderer->filename($media, $format)), $renderer->render($media, $format));
        }
        $zip->close();

        return response()->download($path, 'postare-'.$post->publish_on->format('Y-m-d').'.zip', ['Cache-Control' => 'no-store'])->deleteFileAfterSend();
    }
}
