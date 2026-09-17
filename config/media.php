<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media disk
    |--------------------------------------------------------------------------
    |
    | Where newly uploaded product images are written. Local development uses the
    | `public` disk; production will set MEDIA_DISK=s3.
    |
    | Moving to S3 is three steps and no code change:
    |   1. composer require league/flysystem-aws-s3-v3
    |   2. set AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_DEFAULT_REGION / AWS_BUCKET
    |   3. set MEDIA_DISK=s3
    |
    | Each image row records the disk it was written to, so flipping this value only
    | affects *new* uploads. Images already on the old disk keep resolving from it, which
    | means the switch needs no migration and no downtime.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Temporary URL lifetime
    |--------------------------------------------------------------------------
    |
    | Used only by disks that sign their URLs, such as a private S3 bucket. Public
    | disks ignore it and return a plain permanent URL.
    |
    */

    'temporary_url_minutes' => (int) env('MEDIA_TEMPORARY_URL_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Product image constraints
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Customer design constraints
    |--------------------------------------------------------------------------
    |
    | A customer's artwork — the image or PDF printed on their bags.
    |
    | **A different disk from product images, deliberately.** A product photo is the
    | business's own marketing and belongs on a public disk. A design is the customer's
    | property, and the `public` disk hands out permanent unauthenticated URLs — one leaked
    | path is a competitor holding somebody's print file. `local` is storage/app/private;
    | production points this at S3 with private visibility, and the signed-URL lifetime
    | above then applies.
    |
    | One size limit for both kinds, not two. Every limit here is mirrored as a pre-flight
    | check in the app, so a second number is a third place that has to agree.
    |
    | `svg` is absent on purpose and must stay absent: an SVG is an HTML document, and one
    | served from our own origin is stored XSS.
    |
    */

    'customer_designs' => [
        'disk' => env('MEDIA_DESIGNS_DISK', 'local'),
        'max_kilobytes' => (int) env('MEDIA_DESIGN_MAX_KILOBYTES', 25600),
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'mimetypes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        'max_per_customer' => (int) env('MEDIA_DESIGNS_MAX_PER_CUSTOMER', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment receipt constraints
    |--------------------------------------------------------------------------
    |
    | The scanned receipt (الواصل) proving a bank transfer landed. Required on a transfer
    | and accepted on any other method.
    |
    | **PDF or an image, the same shapes as a customer design.** This began as PDF-only —
    | the document a bank produces — but the receipt that actually arrives here is a phone
    | photograph or a banking-app screenshot sent over WhatsApp, and refusing those meant
    | refusing the proof the business actually holds. The business asked for images
    | (2026-08-22), so the photograph is now evidence, not a liability.
    |
    | `svg` is absent and must stay absent, exactly as it is for designs: an SVG is an
    | HTML document, and one served from our own origin is stored XSS.
    |
    | **Private disk, like customer designs and unlike product photos.** A receipt carries
    | somebody's bank details, and the `public` disk hands out permanent unauthenticated
    | URLs. `local` is storage/app/private; production points this at S3 with private
    | visibility, and the signed-URL lifetime above then applies.
    |
    */

    'payment_receipts' => [
        'disk' => env('MEDIA_RECEIPTS_DISK', 'local'),
        'max_kilobytes' => (int) env('MEDIA_RECEIPT_MAX_KILOBYTES', 10240),
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'mimetypes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
    ],

    'product_images' => [
        'max_kilobytes' => (int) env('MEDIA_MAX_KILOBYTES', 5120),
        'mimes' => ['jpeg', 'jpg', 'png', 'webp'],

        /*
        | How many photographs one product may carry.
        |
        | A cap rather than none, for the reason customer designs have one: the images travel
        | inside every `GET /products/{id}`, so an unbounded product makes its own detail
        | response heavier for everybody who opens it — including the catalogue screens that
        | only ever draw the primary.
        |
        | Five was the business's own figure (2026-08-23): enough for the bag from each side
        | plus a colour or two, and few enough that the grid on a phone stays one screen.
        | Raising it is this number and nothing else — the app reads the same cap through
        | `ProductImageRules`, and a contract test fails when the two drift apart.
        */
        'max_per_product' => (int) env('MEDIA_MAX_IMAGES_PER_PRODUCT', 5),
    ],

];
