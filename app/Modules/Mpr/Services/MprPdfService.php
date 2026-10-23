<?php

namespace App\Modules\Mpr\Services;

use App\Models\Mpr;
use App\Modules\Core\Models\User;
use App\Support\Pdf\Pdf;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;

class MprPdfService
{
    /**
     * Mode 1: Index Row Click - Single MPR Download
     */
    public function generateSingleReport(array $mprData)
    {
        $userId = $mprData['user_id'] ?? null;
        $user = $userId ? User::find($userId) : null;
        $userName = $user ? $user->name : 'Unknown_User';

        $cleanFields = $this->mapMprFields($mprData, $userName);
        $fileName = Str::studly($userName).'_'.time().'.pdf';

        return [
            'pdf' => Pdf::view('pdfs.mpr-report', [
                'mode' => 'single',
                'reportFields' => $cleanFields,
                'contentLabels' => $this->getContentLabels(),
            ])->format('a4')->portrait(),
            'file_name' => $fileName,
        ];
    }

    /**
     * Mode 2: Top Button Click - User Dropdown Modal
     */
    public function generateComparisonReport($userId)
    {
        $user = User::find($userId);
        if (! $user) {
            return null;
        }

        $userName = $user->name;
        $fileName = Str::studly($userName).'_'.time().'.pdf';

        $mprRecords = Mpr::where('user_id', $userId)
            ->orderBy('id', 'desc')
            ->take(2)
            ->get();

        if ($mprRecords->isEmpty()) {
            return ['empty' => true];
        }

        if ($mprRecords->count() === 1) {
            $singleMpr = $mprRecords->first()->toArray();
            $cleanFields = $this->mapMprFields($singleMpr, $userName);

            $pdfOutput = Pdf::view('pdfs.mpr-report', [
                'mode' => 'single',
                'reportFields' => $cleanFields,
                'contentLabels' => $this->getContentLabels(),
            ])
                ->format('a4')
                ->portrait();

            return ['pdf' => $pdfOutput, 'file_name' => $fileName, 'empty' => false];
        }

        $latestMpr = $mprRecords->first()->toArray();
        $previousMpr = $mprRecords->get(1)->toArray();

        $latestData = $this->mapMprFields($latestMpr, $userName);
        $previousData = $this->mapMprFields($previousMpr, $userName);

        $pdfOutput = Pdf::view('pdfs.mpr-report', [
            'mode' => 'comparison',
            'latest' => $latestData,
            'previous' => $previousData,
            'hasPrevious' => true,
            'contentLabels' => $this->getContentLabels(),
        ])
            ->format('a4')
            ->landscape();

        return ['pdf' => $pdfOutput, 'file_name' => $fileName, 'empty' => false];
    }

    /**
     * Utility Helper: HTML tags cleaning and Date formatting
     */
    private function mapMprFields(array $mpr, $userName)
    {
        $mprDate = '---';
        if (! empty($mpr['mpr_date'])) {
            try {
                $mprDate = Carbon::parse($mpr['mpr_date'])->format('d-M-Y');
            } catch (Exception $e) {
                $mprDate = $mpr['mpr_date'];
            }
        }

        return [
            'User Name' => $userName,
            'MPR Date' => $mprDate,
            'Feedback' => strip_tags($mpr['feedback'] ?? '---'),
            'Topics Scope' => strip_tags($mpr['topics_scope'] ?? '---'),
            'Recent Module' => strip_tags($mpr['recent_module'] ?? '---'),
            'Employee Request' => strip_tags($mpr['employee_request'] ?? '---'),
            'Next MPR Goal' => strip_tags($mpr['next_mpr_goal'] ?? '---'),
            'What have you learnt this month?' => strip_tags($mpr['current_month_learning'] ?? '---'),
        ];
    }

    /**
     * Centralized Dynamic Labels Generator
     * This keeps the View 100% clean of raw PHP logical mapping blocks.
     */
    private function getContentLabels(): array
    {
        return [
            1 => 'Feedback',
            2 => 'Topics Scope',
            3 => 'Recent Module',
            4 => 'Employee Request',
            5 => 'Next MPR Goal',
            6 => 'What have you learnt this month?',
        ];
    }
}
