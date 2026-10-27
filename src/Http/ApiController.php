<?php

/*
 * ApiController.php - jedno wejście API dla rdzenia i wszystkich modułów.
 *
 *   GET|POST /plugin/Netella-Reg/api/core/<akcja>     dziennik, synchronizacja
 *   GET|POST /plugin/Netella-Reg/api/<moduł>/<akcja>  RegModule::api()
 *
 * Moduł jest rozwiązywany przy żądaniu, więc moduły z innych pakietów nie
 * muszą rejestrować własnych tras. Uprawnienia sprawdza kontroler na
 * podstawie RegModule::apiPermission(), zanim moduł dostanie żądanie.
 *
 * Part of the Netella-Reg LibreNMS plugin. Licencja GPL-3.0-or-later.
 */

namespace Ispnetella\NetellaReg\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Ispnetella\NetellaReg\Core\Access;
use Ispnetella\NetellaReg\Core\ApiException;
use Ispnetella\NetellaReg\Core\AuditLog;
use Ispnetella\NetellaReg\Core\Backup;
use Ispnetella\NetellaReg\Core\Db;
use Ispnetella\NetellaReg\Core\ModuleRegistry;
use Ispnetella\NetellaReg\Core\SyncRunner;

final class ApiController extends Controller
{
    public function __construct(private readonly ModuleRegistry $registry)
    {
    }

    public function __invoke(Request $request, string $module, string $action): JsonResponse
    {
        try {
            if (! Access::canView()) {
                throw new ApiException('Brak uprawnień.', 403);
            }

            Db::ensure($this->registry);

            if ($module === 'core') {
                return $this->ok($this->core($action, $request));
            }

            $m = $this->registry->enabledModule($module);
            if ($m === null) {
                throw new ApiException("Moduł '$module' nie istnieje albo jest wyłączony.", 404);
            }

            if (! Access::can($m->apiPermission($action, $request->method()))) {
                throw new ApiException('Brak uprawnień do tej czynności.', 403);
            }

            return $this->ok($m->api($action, $request));
        } catch (ApiException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], $e->status);
        } catch (\Throwable $e) {
            Log::error("Netella-Reg API $module/$action: " . $e->getMessage());

            return response()->json(['ok' => false, 'error' => 'Błąd serwera: ' . $e->getMessage()], 500);
        }
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['ok' => true] + $data);
    }

    private function core(string $action, Request $request): array
    {
        switch ($action) {
            case 'audit':
                $filters = [];
                foreach (['module', 'action', 'source', 'q'] as $f) {
                    $v = trim((string) $request->query($f, ''));
                    if ($v !== '') {
                        $filters[$f] = $v;
                    }
                }

                return AuditLog::page($filters, (int) $request->query('page', 1), (int) $request->query('per_page', 50))
                    + ['labels' => AuditLog::LABELS];

            case 'sync':
                if (! $request->isMethod('POST')) {
                    throw new ApiException('Wymagany POST.', 405);
                }
                if (! Access::canAdmin()) {
                    throw new ApiException('Synchronizację ręczną może uruchomić tylko administrator wtyczki.', 403);
                }

                $log = [];
                $results = (new SyncRunner($this->registry))->run(null, function (string $m) use (&$log) {
                    $log[] = $m;
                });

                return ['results' => $results, 'log' => $log];

            case 'backups':
            case 'backup_create':
                if (! Access::canAdmin()) {
                    throw new ApiException('Kopie zapasowe są dostępne tylko dla administratora wtyczki.', 403);
                }
                $created = null;
                if ($action === 'backup_create') {
                    if (! $request->isMethod('POST')) {
                        throw new ApiException('Wymagany POST.', 405);
                    }
                    $created = Backup::create(Backup::MANUAL);
                }

                return ['created' => $created, 'backups' => array_map(fn ($b) => $b + [
                    'url' => url('plugin/' . \Ispnetella\NetellaReg\Plugin::NAME . '/backup/' . $b['file']),
                ], Backup::list()), 'keep' => Backup::keep()];

            case 'status':
                $out = [];
                foreach ($this->registry->enabled() as $key => $m) {
                    $out[$key] = ['name' => $m->name(), 'sync' => SyncRunner::last($key)];
                }

                return ['modules' => $out, 'versions' => Db::versions()];
        }

        throw new ApiException("Nieznana akcja '$action'", 404);
    }
}
