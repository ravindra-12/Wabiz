<?php

namespace App\Services;

use App\Models\Template;
use Illuminate\Support\Facades\Log;

class TemplateService
{
    /**
     * Available template categories.
     */
    public const CATEGORIES = [
        'welcome',
        'followup',
        'order',
        'marketing',
        'support',
        'custom',
    ];

    /**
     * System-recognized variables that can be used in templates.
     */
    public const SYSTEM_VARIABLES = [
        'name'       => 'Lead / customer name',
        'phone'      => 'Lead phone number',
        'order_id'   => 'Order ID',
        'total'      => 'Order total price',
        'status'     => 'Order or lead status',
        'product'    => 'Product name',
        'date'       => 'Current date',
        'company'    => 'Your company name',
    ];

    /**
     * Render a template with the given data.
     *
     * @param Template $template
     * @param array $data
     * @return string
     */
    public function render(Template $template, array $data = []): string
    {
        return $template->render($data);
    }

    /**
     * Render a template by slug for a specific tenant.
     *
     * @param int $userId
     * @param string $slug
     * @param array $data
     * @return string|null  Returns null if template not found or inactive
     */
    public function renderBySlug(int $userId, string $slug, array $data = []): ?string
    {
        $template = Template::where('user_id', $userId)
            ->where('slug', $slug)
            ->where('status', 'active')
            ->first();

        if (!$template) {
            Log::warning('Template not found or inactive.', [
                'user_id' => $userId,
                'slug' => $slug,
            ]);
            return null;
        }

        return $template->render($data);
    }

    /**
     * Render a template by category (first active match).
     * Useful for automated flows like order notifications.
     *
     * @param int $userId
     * @param string $category
     * @param array $data
     * @return string|null
     */
    public function renderByCategory(int $userId, string $category, array $data = []): ?string
    {
        $template = Template::where('user_id', $userId)
            ->where('category', $category)
            ->where('status', 'active')
            ->first();

        if (!$template) {
            return null;
        }

        return $template->render($data);
    }

    /**
     * Get a preview of a template with sample data.
     *
     * @param Template $template
     * @param array $sampleData
     * @return array
     */
    public function preview(Template $template, array $sampleData = []): array
    {
        $extractedVars = $template->extractVariables();

        // Fill missing sample data with placeholder labels
        $mergedData = $sampleData;
        foreach ($extractedVars as $var) {
            if (!isset($mergedData[$var])) {
                $mergedData[$var] = "[{$var}]";
            }
        }

        return [
            'original_content' => $template->content,
            'rendered_content' => $template->render($mergedData),
            'variables_found'  => $extractedVars,
            'sample_data_used' => $mergedData,
        ];
    }
}
