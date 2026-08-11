<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\GraphBuilderService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use \Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;

class GraphsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('hasStreams');
    }

    public function home(): View|Application|Factory|ApplicationContract
    {
        return view('manager.graph')
            ->with('stream', \Auth::user()->process);
    }

    public function getRuleStat($id): View|Application|Factory|ApplicationContract
    {
        return view('manager.ruleGraph',
            ['id' => $id, 'stream' => \Auth::user()->process]);
    }

    public function getRuleStatData(
        $id,
        GraphBuilderService $builderService,
        Request $request
    ): JsonResponse
    {
        if ( ! $request->has('dateFrom')) {
            abort(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'dateFrom field is mandatory'
            );
        }

        if ( ! $request->has('dateTo')) {
            abort(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'dateTo field is mandatory'
            );
        }

        $builderService = $this->checkActingAs($builderService);

        if ( ! empty($id)) {
            return response()->json($builderService->getRuleData($id,
                strtotime($request->input('dateFrom')),
                strtotime($request->input('dateTo'))));
        }

        return response()->json(
            ['message'=>'ID is mandatory'],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    public function getGraph(GraphBuilderService $builderService, Request $request): JsonResponse
    {
        if ( ! $request->has('dateFrom')) {
            abort(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'dateFrom field is mandatory'
            );
        }

        if ( ! $request->has('dateTo')) {
            abort(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'dateTo field is mandatory'
            );
        }

        if ($errors = $builderService->getColumnarErrors()) {
            abort(
                Response::HTTP_SERVICE_UNAVAILABLE,
                "ColumnarService error: " . $errors
            );
        }

        $builderService = $this->checkActingAs($builderService);

        if (!empty($request->input('section'))) {
            $reflector = new ReflectionClass(GraphBuilderService::class);
            $section = str_replace('-tab', '', $request->input('section'));
            if (in_array($section, $reflector->getConstants())) {
                return response()->json(
                    $builderService->getData(
                        $section,
                        0,
                        strtotime($request->input('dateFrom')),
                        strtotime($request->input('dateTo')))
                );
            }
        }

        return response()->json(
            ['message'=>'section is mandatory'],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws AuthenticationException
     * @throws NotFoundExceptionInterface
     */
    private function checkActingAs(GraphBuilderService $builderService): GraphBuilderService
    {
        if (\Auth::user()->hasRole(Role::ROLE_ADMIN)) {
            $request = request();
            if ($request->has('actingAs')) {
                $actingAs = $request->get('actingAs');
            } else {
                throw new AuthenticationException('Admin must pass actingAs variable');
            }

            if ($actingAs !== null) {
                $builderService->setStreamId($actingAs);
            }
        }

        return $builderService;
    }
}
