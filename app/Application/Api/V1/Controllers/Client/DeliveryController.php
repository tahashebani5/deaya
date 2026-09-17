<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers\Client;

use App\Application\Api\V1\Resources\Client\ClientCityResource;
use App\Application\Controller;
use App\Domain\Delivery\DeliveryService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Delivery
 *
 * Where an order can be sent, and what it costs to send it there.
 *
 * **This endpoint exists because `RequestOrderRequest` requires `city_id`.** An app that must
 * name a destination it has no way to list is an app that cannot place an order — so the picker
 * has to be readable by anyone who can order, which is every signed-in customer and no
 * permission at all.
 *
 * **Never paged.** A destination picker is one screen that has to contain every answer: a paged
 * one is a picker somebody scrolls off the end of and concludes we do not deliver to their city.
 * The regions come with their cities in the same call, because a customer who has chosen «مصراتة»
 * should not then wait on a second request to choose the street.
 */
class DeliveryController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly DeliveryService $delivery) {}

    /**
     * Where we deliver
     *
     * Every destination, in the order the business keeps them, each with its neighbourhoods.
     */
    public function cities(): JsonResponse
    {
        return $this->success(ClientCityResource::collection($this->delivery->deliveryMap()));
    }
}
