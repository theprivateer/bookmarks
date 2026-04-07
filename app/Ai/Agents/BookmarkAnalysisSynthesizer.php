<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[UseCheapestModel]
#[MaxTokens(1024)]
#[Temperature(0.2)]
class BookmarkAnalysisSynthesizer implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a bookmark analysis synthesizer. Given chunk-level summaries and candidate tags for a long web page, produce a concise final 2-3 sentence summary and up to 5 lowercase descriptive tags. Prefer the most representative themes, deduplicate overlapping concepts, and avoid generic tags.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'tags' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
