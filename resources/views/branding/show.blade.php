<x-layouts::app :title="__('Branding')">
    <main data-testid="branding-settings" class="flex h-full w-full flex-1 flex-col gap-8 rounded-xl">
        <header>
            <flux:heading size="xl">{{ __('Branding') }}</flux:heading>
            <flux:text>{{ __('Draft changes stay private until you apply them.') }}</flux:text>
        </header>

        @if (session('status'))
            <div data-testid="branding-status" class="rounded-lg border border-green-300 bg-green-50 p-3 text-green-900">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div data-testid="branding-errors" class="rounded-lg border border-red-300 bg-red-50 p-3 text-red-900">
                <ul class="list-disc pl-5">
                    @foreach ($errors->getMessages() as $field => $messages)
                        @foreach ($messages as $error)
                            <li><span class="font-medium">{{ $errorFieldNames[$field] ?? \Illuminate\Support\Str::headline(str_replace('.', ' ', $field)) }}:</span> {{ $error }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif

        <section data-testid="branding-current" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center justify-between gap-4">
                <flux:heading size="lg">{{ __('Current brand') }}</flux:heading>
                <span>{{ $state['current_version'] === null ? __('Defaults') : __('Version :version', ['version' => $state['current_version']]) }}</span>
            </div>
            <dl class="grid gap-2 sm:grid-cols-2">
                <div><dt class="font-medium">{{ __('Display name') }}</dt><dd>{{ $state['current']['display_name'] }}</dd></div>
                <div><dt class="font-medium">{{ __('Default layout') }}</dt><dd>{{ $state['current']['default_layout'] }}</dd></div>
                <div><dt class="font-medium">{{ __('Heading font') }}</dt><dd>{{ $state['current']['fonts']['heading']['value'] }}</dd></div>
                <div><dt class="font-medium">{{ __('Body font') }}</dt><dd>{{ $state['current']['fonts']['body']['value'] }}</dd></div>
            </dl>
        </section>

        <form data-testid="branding-draft-form" method="POST" action="{{ route('branding.draft.update') }}" class="space-y-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            @csrf
            @method('PATCH')
            <flux:heading size="lg">{{ $state['draft'] === null ? __('Create draft') : __('Edit draft') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Display name') }}</flux:label>
                <flux:input name="display_name" value="{{ old('display_name', $editing['display_name']) }}" required maxlength="120" />
            </flux:field>

            @foreach (['light' => __('Light colours'), 'dark' => __('Dark colours')] as $mode => $label)
                <fieldset data-testid="branding-colours-{{ $mode }}" class="space-y-3">
                    <legend class="font-semibold">{{ $label }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($contract['colour_tokens'] as $token)
                            <flux:field>
                                <flux:label>{{ str_replace('_', ' ', ucfirst($token)) }}</flux:label>
                                <flux:input name="colours[{{ $mode }}][{{ $token }}]" value="{{ old('colours.'.$mode.'.'.$token, $editing['colours'][$mode][$token]) }}" required />
                            </flux:field>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            <div class="grid gap-6 lg:grid-cols-2">
                <fieldset data-testid="branding-spacing" class="space-y-3">
                    <legend class="font-semibold">{{ __('Spacing scale') }}</legend>
                    @foreach ($contract['spacing_tokens'] as $token)
                        <flux:field>
                            <flux:label>{{ strtoupper($token) }}</flux:label>
                            <flux:input name="spacing[{{ $token }}]" value="{{ old('spacing.'.$token, $editing['spacing'][$token]) }}" required />
                        </flux:field>
                    @endforeach
                </fieldset>
                <fieldset data-testid="branding-radius" class="space-y-3">
                    <legend class="font-semibold">{{ __('Radius scale') }}</legend>
                    @foreach ($contract['radius_tokens'] as $token)
                        <flux:field>
                            <flux:label>{{ strtoupper($token) }}</flux:label>
                            <flux:input name="radius[{{ $token }}]" value="{{ old('radius.'.$token, $editing['radius'][$token]) }}" required />
                        </flux:field>
                    @endforeach
                </fieldset>
            </div>

            <fieldset data-testid="branding-fonts" class="grid gap-4 sm:grid-cols-2">
                <legend class="font-semibold sm:col-span-2">{{ __('System font stacks') }}</legend>
                @foreach (['heading', 'body'] as $fontSlot)
                    <input type="hidden" name="fonts[{{ $fontSlot }}][type]" value="stack">
                    <flux:field>
                        <flux:label>{{ ucfirst($fontSlot) }}</flux:label>
                        <flux:select name="fonts[{{ $fontSlot }}][value]">
                            @foreach ($contract['font_stacks'] as $stack)
                                <option value="{{ $stack }}" @selected(old('fonts.'.$fontSlot.'.value', $editing['fonts'][$fontSlot]['value']) === $stack)>{{ $stack }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                @endforeach
            </fieldset>

            <fieldset data-testid="branding-asset-selections" class="grid gap-4 sm:grid-cols-3">
                <legend class="font-semibold sm:col-span-3">{{ __('Selected assets') }}</legend>
                @foreach ($contract['assets']['slots'] as $slot => $kind)
                    <flux:field>
                        <flux:label>{{ str_replace('_', ' ', ucfirst($slot)) }}</flux:label>
                        <flux:select name="assets[{{ $slot }}]">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($assets as $asset)
                                @if ($asset->kind->value === $kind)
                                    <option value="{{ $asset->id }}" @selected(old('assets.'.$slot, $editing['assets'][$slot]) === $asset->id)>{{ $asset->content_type }} · {{ $asset->size_bytes }} bytes · {{ substr($asset->id, 0, 8) }}</option>
                                @endif
                            @endforeach
                        </flux:select>
                    </flux:field>
                @endforeach
            </fieldset>

            <flux:field>
                <flux:label>{{ __('Default layout') }}</flux:label>
                <flux:select name="default_layout">
                    @foreach ($contract['layouts'] as $layout)
                        <option value="{{ $layout['name'] }}" @selected(old('default_layout', $editing['default_layout']) === $layout['name'])>{{ ucfirst($layout['name']) }} — {{ $layout['purpose'] }}</option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Tone notes') }}</flux:label>
                <flux:textarea name="tone_notes" rows="4">{{ old('tone_notes', $editing['tone_notes']) }}</flux:textarea>
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Header HTML') }}</flux:label>
                <flux:textarea name="header_html" rows="6">{{ old('header_html', $editing['header_html']) }}</flux:textarea>
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Footer HTML') }}</flux:label>
                <flux:textarea name="footer_html" rows="6">{{ old('footer_html', $editing['footer_html']) }}</flux:textarea>
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Custom CSS') }}</flux:label>
                <flux:textarea name="custom_css" rows="8">{{ old('custom_css', $editing['custom_css']) }}</flux:textarea>
            </flux:field>

            <flux:button type="submit" variant="primary">{{ __('Save draft') }}</flux:button>
        </form>

        <section data-testid="branding-assets" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Upload image assets') }}</flux:heading>
            <flux:text>{{ __('PNG, JPEG, or WebP only. Font uploads are not supported.') }}</flux:text>
            @foreach (['logo_light' => __('Light logo'), 'logo_dark' => __('Dark logo'), 'mark' => __('Mark')] as $slot => $label)
                <form method="POST" action="{{ route('branding.assets.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <input type="hidden" name="slot" value="{{ $slot }}">
                    <flux:field>
                        <flux:label>{{ $label }}</flux:label>
                        <flux:input type="file" name="asset" accept="image/png,image/jpeg,image/webp" required />
                    </flux:field>
                    <flux:button type="submit">{{ __('Upload and select') }}</flux:button>
                </form>
            @endforeach
        </section>

        <section data-testid="branding-preview" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Draft preview') }}</flux:heading>
            @if ($previewUrl)
                <iframe title="{{ __('Brand draft preview') }}" src="{{ $previewUrl }}" sandbox="allow-scripts" referrerpolicy="no-referrer" class="h-[48rem] w-full rounded-lg border"></iframe>
                <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer" class="underline">{{ __('Open preview in a new tab') }}</a>
            @else
                <p>{{ __('Save a draft to create a temporary preview.') }}</p>
            @endif
        </section>

        <section data-testid="branding-apply" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Apply draft') }}</flux:heading>
            <form method="POST" action="{{ route('branding.apply') }}">
                @csrf
                <input type="hidden" name="draft_fingerprint" value="{{ $draftFingerprint }}">
                <flux:button type="submit" variant="primary" :disabled="$state['draft'] === null">{{ __('Apply brand draft') }}</flux:button>
            </form>
        </section>

        <section data-testid="branding-history" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Version history') }}</flux:heading>
            <flux:text>{{ __('Reverting creates a new version and discards any unsaved draft.') }}</flux:text>
            @forelse ($state['versions'] as $version)
                <article data-testid="branding-history-row" class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 py-3 first:border-0 dark:border-zinc-700">
                    <div>
                        <strong>{{ __('Version :version', ['version' => $version['version_number']]) }}</strong>
                        @if ($version['is_current']) <span>{{ __('Current') }}</span> @endif
                        <time class="block text-sm" datetime="{{ $version['applied_at'] }}">{{ $version['applied_at'] }}</time>
                    </div>
                    @unless ($version['is_current'])
                        <form method="POST" action="{{ route('branding.versions.revert', ['version' => $version['version_number']]) }}">
                            @csrf
                            <flux:button type="submit">{{ __('Revert to this version') }}</flux:button>
                        </form>
                    @endunless
                </article>
            @empty
                <p data-testid="branding-history-empty">{{ __('No applied versions yet.') }}</p>
            @endforelse
        </section>
    </main>
</x-layouts::app>
