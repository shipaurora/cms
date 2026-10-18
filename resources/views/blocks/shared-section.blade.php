@props(['data' => []])

@php
    use Aurora\Blocks\Blocks;
    use Aurora\Models\SharedSection;

    $section = SharedSection::query()->find($data['shared_section_id'] ?? null);
@endphp

{{-- A shared section, as it is wherever it's shown (Aurora\Blocks\SharedSectionBlock). --}}
@if ($section)
    {!! $section->draw(fn () => view('aurora::blocks.shared-section-blocks', ['blocks' => $section->localizedBlocks()])->render()) !!}
@elseif (Blocks::isEditing())
    <div class="rounded-2xl border-2 border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500">{{ __('Choose a shared section in the panel →') }}</div>
@endif
