<?php

declare(strict_types=1);

namespace Studio\Gesso\Symfony;

use Studio\Gesso\UploadedPart;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function is_array;

/**
 * Read a form request body out of an HttpFoundation `Request` as the field map
 * the body validator expects (issue #405).
 *
 * HttpFoundation does not keep the serialized bytes of a `multipart/form-data`
 * request — and for a form POST built by the test client there may never have
 * been any — so the parsed `request` / `files` bags are the only faithful
 * source. Uploaded files become {@see UploadedPart}s carrying the client
 * Content-Type and filename; the bytes are not read.
 *
 * Shared by the Symfony and Laravel adapters (Laravel's `Request` extends this
 * one). It is only reachable from those adapters, so the optional
 * symfony/http-foundation dependency stays optional.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class HttpFoundationFormBody
{
    /**
     * @return null|array<string, mixed> null when the request carries no form
     *                                   fields and no files — the caller decides whether that is an absent
     *                                   body or a raw payload to pass through
     */
    public static function fields(Request $request): ?array
    {
        $fields = $request->request->all();
        $files = $request->files->all();

        if ($fields === [] && $files === []) {
            return null;
        }

        return [...$fields, ...self::toParts($files)];
    }

    /**
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, mixed>
     */
    private static function toParts(array $files): array
    {
        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                if (!$file->isValid()) {
                    unset($files[$key]);

                    continue;
                }

                $files[$key] = new UploadedPart($file->getClientMimeType(), $file->getClientOriginalName());
            } elseif (is_array($file)) {
                $files[$key] = self::toParts($file);
            }
        }

        return $files;
    }
}
