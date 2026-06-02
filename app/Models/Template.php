<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'category',
        'content',
        'variables',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'variables' => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * The user (tenant) who owns this template.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * Scope: only active templates.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: filter by category.
     */
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Render the template content by replacing {{variable}} placeholders.
     *
     * @param array $data Key-value pairs, e.g. ['name' => 'John', 'order_id' => '123']
     * @return string
     */
    public function render(array $data = []): string
    {
        $content = $this->content;

        foreach ($data as $key => $value) {
            $content = str_replace('{{' . $key . '}}', (string) $value, $content);
        }

        return $content;
    }

    /**
     * Extract all {{variable}} placeholders from the content.
     *
     * @return array
     */
    public function extractVariables(): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $this->content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Auto-generate a unique slug from the template name.
     */
    public static function generateSlug(string $name, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);
        $original = $slug;
        $counter = 1;

        $query = static::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        while ($query->exists()) {
            $slug = "{$original}-{$counter}";
            $counter++;
            $query = static::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
        }

        return $slug;
    }
}
