<?php

namespace App\Support;

final class PageComponentStylesheet
{
    /** @return array<string, string> */
    public function rules(): array
    {
        $rules = [
            'capstan-page' => 'color:var(--capstan-color-text);background:var(--capstan-color-surface);font-family:var(--capstan-font-body);padding:var(--capstan-space-lg)',
            'capstan-layout-report' => 'margin:var(--capstan-space-xl);padding:var(--capstan-space-xl)',
            'capstan-layout-dashboard' => 'padding:var(--capstan-space-md);gap:var(--capstan-space-md)',
            'capstan-layout-brief' => 'margin:var(--capstan-space-lg);padding:var(--capstan-space-lg)',
            'capstan-content' => 'padding:var(--capstan-space-md)',
            'capstan-header' => 'padding:var(--capstan-space-md);color:var(--capstan-color-primary);font-family:var(--capstan-font-heading)',
            'capstan-footer' => 'padding:var(--capstan-space-md);color:var(--capstan-color-text-muted)',
            'capstan-card' => 'padding:var(--capstan-space-lg);border:solid var(--capstan-space-xs) var(--capstan-color-border);border-radius:var(--capstan-radius-md);background:var(--capstan-color-surface-muted)',
            'capstan-grid' => 'display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,calc(var(--capstan-space-xl) * 8)),1fr));gap:var(--capstan-space-md)',
            'capstan-stat' => 'padding:var(--capstan-space-lg);background:var(--capstan-color-surface-muted);border-radius:var(--capstan-radius-md)',
            'capstan-stat-value' => 'font-family:var(--capstan-font-heading);font-size:clamp(var(--capstan-space-lg),8vw,calc(var(--capstan-space-xl) * 2));color:var(--capstan-color-primary);white-space:nowrap',
            'capstan-stat-label' => 'color:var(--capstan-color-text-muted)',
            'capstan-table' => 'width:100%;border-spacing:var(--capstan-space-xs);color:var(--capstan-color-text);background:var(--capstan-color-surface-muted)',
            'capstan-badge' => 'padding:var(--capstan-space-xs) var(--capstan-space-sm);border-radius:var(--capstan-radius-pill);color:var(--capstan-color-primary);background:var(--capstan-color-surface-muted)',
            'capstan-callout' => 'padding:var(--capstan-space-lg);border-left:solid var(--capstan-space-xs) var(--capstan-color-accent);background:var(--capstan-color-surface-muted)',
        ];
        foreach (['success', 'warning', 'danger'] as $state) {
            $rules['capstan-badge-'.$state] = 'color:var(--capstan-color-'.$state.')';
            $rules['capstan-callout-'.$state] = 'border-color:var(--capstan-color-'.$state.')';
        }
        foreach (range(1, 5) as $series) {
            $rules['capstan-chart-series-'.$series] = 'color:var(--capstan-color-chart-'.$series.');fill:var(--capstan-color-chart-'.$series.');stroke:var(--capstan-color-chart-'.$series.')';
        }

        return $rules;
    }

    public function serialize(): string
    {
        $css = '';
        foreach ($this->rules() as $class => $declarations) {
            $css .= '.'.$class.'{'.$declarations.'}';
        }

        return $css.'@media (max-width:640px){'
            .'.capstan-layout-report,.capstan-layout-brief{margin:0;padding:var(--capstan-space-md)}'
            .'.capstan-header>div,.capstan-footer>div{display:flex;flex-direction:column;align-items:flex-start}'
            .'.capstan-header img{max-width:100%;height:auto}'
            .'.capstan-table{display:block;overflow-x:auto}'
            .'}';
    }
}
