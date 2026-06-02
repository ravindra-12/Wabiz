<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class TemplateController extends Controller
{
    protected TemplateService $templateService;

    public function __construct(TemplateService $templateService)
    {
        $this->templateService = $templateService;
    }

    /**
     * Get all templates
     *
     * @OA\Get(
     *     path="/templates",
     *     tags={"Templates"},
     *     summary="Get all templates",
     *     description="Retrieve all message templates for the authenticated user with optional filtering by category, status, and search",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="category", in="query", required=false, description="Filter by category", @OA\Schema(type="string", enum={"welcome","followup","order","marketing","support","custom"})),
     *     @OA\Parameter(name="status", in="query", required=false, description="Filter by status", @OA\Schema(type="string", enum={"active","inactive"})),
     *     @OA\Parameter(name="search", in="query", required=false, description="Search by name or content", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", example=15)),
     *     @OA\Response(
     *         response=200,
     *         description="Templates fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Templates fetched successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=500, description="Server error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request)
    {
        try {
            $query = Template::where('user_id', auth()->id());

            // Filter by category
            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Search by name or content
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('content', 'like', "%{$search}%");
                });
            }

            $templates = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'status'  => true,
                'message' => 'Templates fetched successfully',
                'data'    => $templates
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Error fetching templates',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single template
     *
     * @OA\Get(
     *     path="/templates/{id}",
     *     tags={"Templates"},
     *     summary="Get a single template",
     *     description="Retrieve a specific template by ID with its extracted variables",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Template fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="template", type="object"),
     *                 @OA\Property(property="extracted_variables", type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Template not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function show($id)
    {
        try {
            $template = Template::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$template) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Template not found'
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Template fetched successfully',
                'data'    => [
                    'template'            => $template,
                    'extracted_variables'  => $template->extractVariables(),
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Error fetching template',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create template
     *
     * @OA\Post(
     *     path="/templates",
     *     tags={"Templates"},
     *     summary="Create a new template",
     *     description="Create a new message template with dynamic variable support. Variables use {{variable_name}} syntax.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"name", "category", "content"},
     *             @OA\Property(property="name", type="string", example="Order Shipped"),
     *             @OA\Property(property="category", type="string", enum={"welcome","followup","order","marketing","support","custom"}, example="order"),
     *             @OA\Property(property="content", type="string", example="Hi {{name}}, your order #{{order_id}} has been shipped! Total: Rs. {{total}}"),
     *             @OA\Property(property="variables", type="array", nullable=true, @OA\Items(type="string"), example={"name","order_id","total"}),
     *             @OA\Property(property="status", type="string", enum={"active","inactive"}, example="active")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Template created successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'      => 'required|string|max:150',
                'category'  => 'required|string|in:welcome,followup,order,marketing,support,custom',
                'content'   => 'required|string|max:4096',
                'variables' => 'nullable|array',
                'variables.*' => 'string|max:50',
                'status'    => 'sometimes|in:active,inactive',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $userId = auth()->id();
            $slug = Template::generateSlug($request->name);

            // Auto-extract variables from content if not provided
            $variables = $request->variables;
            if (!$variables) {
                preg_match_all('/\{\{(\w+)\}\}/', $request->content, $matches);
                $variables = !empty($matches[1]) ? array_unique($matches[1]) : null;
            }

            DB::beginTransaction();

            $template = Template::create([
                'user_id'   => $userId,
                'name'      => $request->name,
                'slug'      => $slug,
                'category'  => $request->category,
                'content'   => $request->content,
                'variables' => $variables ? array_values($variables) : null,
                'status'    => $request->status ?? 'active',
            ]);

            DB::commit();

            Log::info('Template created.', [
                'template_id' => $template->id,
                'slug'        => $slug,
                'user_id'     => $userId,
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Template created successfully',
                'data'    => $template
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error creating template: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error creating template',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update template
     *
     * @OA\Put(
     *     path="/templates/{id}",
     *     tags={"Templates"},
     *     summary="Update a template",
     *     description="Update an existing template's name, category, content, variables, or status",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="category", type="string", enum={"welcome","followup","order","marketing","support","custom"}),
     *             @OA\Property(property="content", type="string"),
     *             @OA\Property(property="variables", type="array", nullable=true, @OA\Items(type="string")),
     *             @OA\Property(property="status", type="string", enum={"active","inactive"})
     *         )
     *     ),
     *     @OA\Response(response=200, description="Template updated successfully"),
     *     @OA\Response(response=404, description="Template not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function update(Request $request, $id)
    {
        try {
            $template = Template::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$template) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Template not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name'        => 'sometimes|string|max:150',
                'category'    => 'sometimes|string|in:welcome,followup,order,marketing,support,custom',
                'content'     => 'sometimes|string|max:4096',
                'variables'   => 'nullable|array',
                'variables.*' => 'string|max:50',
                'status'      => 'sometimes|in:active,inactive',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $updateData = $request->only(['name', 'category', 'content', 'variables', 'status']);

            // Regenerate slug if name changed
            if ($request->has('name')) {
                $updateData['slug'] = Template::generateSlug($request->name, $template->id);
            }

            // Auto-extract variables if content changed and variables not provided
            if ($request->has('content') && !$request->has('variables')) {
                preg_match_all('/\{\{(\w+)\}\}/', $request->content, $matches);
                $updateData['variables'] = !empty($matches[1]) ? array_values(array_unique($matches[1])) : null;
            }

            if ($request->has('variables')) {
                $updateData['variables'] = $request->variables ? array_values($request->variables) : null;
            }

            $template->update($updateData);

            DB::commit();

            Log::info('Template updated.', [
                'template_id' => $template->id,
                'user_id'     => auth()->id(),
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Template updated successfully',
                'data'    => $template->fresh()
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Error updating template',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete template
     *
     * @OA\Delete(
     *     path="/templates/{id}",
     *     tags={"Templates"},
     *     summary="Delete a template",
     *     description="Permanently delete a template",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Template deleted successfully"),
     *     @OA\Response(response=404, description="Template not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function destroy($id)
    {
        try {
            $template = Template::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$template) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Template not found'
                ], 404);
            }

            DB::beginTransaction();

            $template->delete();

            DB::commit();

            Log::info('Template deleted.', [
                'template_id' => $id,
                'user_id'     => auth()->id(),
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Template deleted successfully'
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Error deleting template',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate / deactivate template
     *
     * @OA\Patch(
     *     path="/templates/{id}/toggle-status",
     *     tags={"Templates"},
     *     summary="Toggle template status",
     *     description="Activate or deactivate a template",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"active","inactive"}, example="inactive")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Template status updated successfully"),
     *     @OA\Response(response=404, description="Template not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function toggleStatus(Request $request, $id)
    {
        try {
            $template = Template::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$template) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Template not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:active,inactive',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $template->update(['status' => $request->status]);

            Log::info('Template status toggled.', [
                'template_id' => $template->id,
                'new_status'  => $request->status,
                'user_id'     => auth()->id(),
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Template status updated successfully',
                'data'    => $template->fresh()
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Error updating template status',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Duplicate template
     *
     * @OA\Post(
     *     path="/templates/{id}/duplicate",
     *     tags={"Templates"},
     *     summary="Duplicate a template",
     *     description="Create a copy of an existing template with a new name and slug",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=201,
     *         description="Template duplicated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Template duplicated successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Template not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function duplicate($id)
    {
        try {
            $original = Template::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$original) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Template not found'
                ], 404);
            }

            $newName = $original->name . ' (Copy)';
            $newSlug = Template::generateSlug($newName);

            DB::beginTransaction();

            $duplicate = Template::create([
                'user_id'   => auth()->id(),
                'name'      => $newName,
                'slug'      => $newSlug,
                'category'  => $original->category,
                'content'   => $original->content,
                'variables' => $original->variables,
                'status'    => 'inactive', // Duplicates start as inactive
            ]);

            DB::commit();

            Log::info('Template duplicated.', [
                'original_id'  => $original->id,
                'duplicate_id' => $duplicate->id,
                'user_id'      => auth()->id(),
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Template duplicated successfully',
                'data'    => $duplicate
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Error duplicating template',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preview template
     *
     * @OA\Post(
     *     path="/templates/{id}/preview",
     *     tags={"Templates"},
     *     summary="Preview a template",
     *     description="Render a template with sample data to see how it will look. Variables not provided in sample_data will show as [variable_name].",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=false,
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="sample_data",
     *                 type="object",
     *                 example={"name": "John Doe", "order_id": "12345", "total": "2500.00"}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template preview generated",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Template preview generated"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="original_content", type="string", example="Hi {{name}}, your order #{{order_id}} has been shipped!"),
     *                 @OA\Property(property="rendered_content", type="string", example="Hi John Doe, your order #12345 has been shipped!"),
     *                 @OA\Property(property="variables_found", type="array", @OA\Items(type="string"), example={"name","order_id"}),
     *                 @OA\Property(property="sample_data_used", type="object", example={"name": "John Doe", "order_id": "12345"})
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Template not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function preview(Request $request, $id)
    {
        try {
            $template = Template::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$template) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Template not found'
                ], 404);
            }

            $sampleData = $request->get('sample_data', []);
            $preview = $this->templateService->preview($template, $sampleData);

            return response()->json([
                'status'  => true,
                'message' => 'Template preview generated',
                'data'    => $preview
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Error generating template preview',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available template categories and system variables
     *
     * @OA\Get(
     *     path="/templates/metadata",
     *     tags={"Templates"},
     *     summary="Get template metadata",
     *     description="Get available categories and system-recognized variables for building templates",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Template metadata fetched",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="categories", type="array", @OA\Items(type="string")),
     *                 @OA\Property(property="system_variables", type="object")
     *             )
     *         )
     *     )
     * )
     */
    public function metadata()
    {
        return response()->json([
            'status'  => true,
            'message' => 'Template metadata fetched',
            'data'    => [
                'categories'       => TemplateService::CATEGORIES,
                'system_variables' => TemplateService::SYSTEM_VARIABLES,
            ]
        ], 200);
    }
}
