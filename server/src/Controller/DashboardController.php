<?php

namespace App\Controller;

use App\Entity\ImportData;
use App\Repository\ImportDataRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function dashboard(ImportDataRepository $importDataRepository): Response 
    {
        /** @var ImportData $importData */
        $importData = $importDataRepository->findOneBy(
            ['city' => $this->getParameter('app.dashboard_city')],
            ['ymd' => 'DESC'] // this should get us the most recent
        );

        $sumGrossPower = 0.0;
        $sumNetPower = 0.0;
        $sumChecked = 0;
        $installedPowerByDay = [];
        $installedUnitsByDay = [];
        $clusters = [
            '0-1 kWp' => 0.0,
            '1-10 kWp' => 0.0,
            '10-30 kWp' => 0.0,
            '30-100 kWp' => 0.0,
            '100-500 kWp' => 0.0,
            '500-1000 kWp' => 0.0,
            '>1000 kWp' => 0.0,
        ];
        $typesOfUse = [];

        foreach ($importData->snapshot as $unit) {
            if ($unit['NetzbetreiberpruefungStatus'] !== 'Ungeprueft') {
                $sumChecked++;
            }

            $day = $unit['Inbetriebnahmedatum'];
            if (! isset($installedPowerByDay[$day])) {
                $installedPowerByDay[$day] = 0.0;
                $installedUnitsByDay[$day] = 0;
            }
            $installedPowerByDay[$day] += (float) $unit['Nettonennleistung'];
            $installedUnitsByDay[$day] += 1;

            $sumGrossPower += (float) $unit['Bruttoleistung'];
            $sumNetPower += (float) $unit['Nettonennleistung'];

            //@TODO not very beutiful
            if ($unit['Nettonennleistung'] < 1) {
                $clusters['0-1 kWp'] += (float) $unit['Nettonennleistung'];
            } elseif ($unit['Nettonennleistung'] < 10) {
                $clusters['1-10 kWp'] += (float) $unit['Nettonennleistung'];
            } elseif ($unit['Nettonennleistung'] < 30) {
                $clusters['10-30 kWp'] += (float) $unit['Nettonennleistung'];
            } elseif ($unit['Nettonennleistung'] < 100) {
                $clusters['30-100 kWp'] += (float) $unit['Nettonennleistung'];
            } elseif ($unit['Nettonennleistung'] < 500) {
                $clusters['100-500 kWp'] += (float) $unit['Nettonennleistung'];
            } elseif ($unit['Nettonennleistung'] < 1000) {
                $clusters['500-1000 kWp'] += (float) $unit['Nettonennleistung'];
            } else {
                $clusters['>1000 kWp'] += (float) $unit['Nettonennleistung'];
            }

            // cluster by Nutzungsbereich
            if (! isset($typesOfUse[$unit['Nutzungsbereich']])) {
                $typesOfUse[$unit['Nutzungsbereich']] = 0;
            }
            $typesOfUse[$unit['Nutzungsbereich']] += (float) $unit['Nettonennleistung'];
        }


        ksort($installedPowerByDay);
        ksort($installedUnitsByDay);

        $installedCumulativeUnits = [];
        $installedCumulativePower = [];

        foreach ($installedUnitsByDay as $day => $units) {
            if (empty($installedCumulativeUnits)) {
                $installedCumulativeUnits[$day] = $units;
            } else {
                $installedCumulativeUnits[$day] = $units + end($installedCumulativeUnits);
            }

            if (empty($installedCumulativePower)) {
                $installedCumulativePower[$day] = $installedPowerByDay[$day];
            } else {
                $installedCumulativePower[$day] = $installedPowerByDay[$day] + end($installedCumulativePower);
            }
        }

        $activeResult = $this->forward(
            'App\Controller\ApiController::activeList',
            ['city' => $this->getParameter('app.dashboard_city')]
        );
        $filteredActive = [];

        foreach (json_decode($activeResult->getContent()) as $active) {
            $filteredActive[$active->ymd] = $active->net;
        }

        return $this->render('default/dashboard.html.twig', [
            'sum' => [
                'units' => count($importData->snapshot),
                'checkedUnits' => $sumChecked,
                'gross_power' => round($sumGrossPower, 1),
                'net_power' => round($sumNetPower, 1),
            ],
            'cumulativeChart' => [
                'labels' => array_keys($installedPowerByDay),
                'values' => [
                    'power' => array_values($installedCumulativePower),
                    'units' => array_values($installedCumulativeUnits),
                ],
            ],
            'pieChart' => [ //@TODO rename
                'labels' => array_keys($clusters),
                'values' => array_values($clusters),
            ],
            'typesOfUse' => [ //@TODO rename
                'labels' => array_keys($typesOfUse),
                'values' => array_values($typesOfUse),
            ],
            'netPowerChart' => [
                'ymd' => array_keys($filteredActive),
                'net' => array_values($filteredActive),
            ],
        ]);
    }

    #[Route('/table', name: 'app_table')]
    public function table(ImportDataRepository $importDataRepository): Response 
    {
        /** @var ImportData $importData */
        $importData = $importDataRepository->findOneBy(
            ['city' => $this->getParameter('app.dashboard_city')], // @TODO make this dynamic
            ['ymd' => 'DESC'] // this gets us the most recent
        );

        return $this->render('default/table.html.twig', [
            'ymd' => $importData->ymd,
            'city' => $importData->city,
            'units' => $importData->snapshot
        ]);
    }

    #[Route('/detail/{ymd}/{city}/{mastr}', name: 'app_detail')]
    public function detail(string $ymd, string $city, string $mastr, ImportDataRepository $importDataRepository): Response 
    {
        return $this->render('default/detail.html.twig', [
            'unit' => $importDataRepository->getUnit($ymd, $city, $mastr)
        ]);
    }

    #[Route('/diff/{city}/{ymd1}/{ymd2}', name: 'app_diff')]
    public function diff(string $city, string $ymd1, string $ymd2): Response 
    {
        $diff = $this->forward(
            'App\Controller\Api\CompareController::compare',
            ['city'  => $city, 'ymd1' => $ymd1, 'ymd2' => $ymd2]
        );
        return $this->render(
            'default/diff.html.twig',
            [
                'diff' => json_decode($diff->getContent(), true),
                'city' => $city,
                'addedYmd' => $ymd1,
                'removedYmd' => $ymd2,
            ]
        );
    }

    #[Route('/imports', name: 'app_imports')]
    public function monitoring(ImportDataRepository $importDataRepository): Response 
    {
        $rows = $importDataRepository->getImportOverview();

        // add link to previous day
        foreach ($rows as $key => $row) {
            if (isset($rows[$key + 1])) {
                $rows[$key]['previous'] = $rows[$key + 1]['ymd'];
            } else {
                $rows[$key]['previous'] = null;
            }
        }

        return $this->render('default/imports.html.twig', [
            'imports' => $rows
        ]);
    }

    #[Route('/documentation', name: 'app_documentation')]
    public function documentation(): Response 
    {
        return new Response('TODO');
    }
}
