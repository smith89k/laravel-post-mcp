<?php

namespace Smith89k\PostMcp\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Throwable;

/**
 * Reads rules, messages and attribute names from a site's FormRequest without
 * running its validation.
 */
class FormRequestRules
{
    public function __construct(protected Container $container) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function rules(string $class, array $input = [], ?Model $post = null): array
    {
        $request = $this->make($class, $input, $post);

        if ($request === null || ! method_exists($request, 'rules')) {
            return [];
        }

        try {
            $rules = $this->container->call([$request, 'rules']);
        } catch (Throwable) {
            return [];
        }

        return is_array($rules) ? $rules : [];
    }

    /**
     * @return array{messages: array<string, string>, attributes: array<string, string>}
     */
    public function messages(string $class): array
    {
        $request = $this->make($class);

        if ($request === null) {
            return ['messages' => [], 'attributes' => []];
        }

        try {
            return ['messages' => $request->messages(), 'attributes' => $request->attributes()];
        } catch (Throwable) {
            return ['messages' => [], 'attributes' => []];
        }
    }

    /**
     * Splits "required|max:255" style rules into a list.
     *
     * @return list<mixed>
     */
    public function normalize(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        return is_array($rules) ? array_values($rules) : [$rules];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function make(string $class, array $input = [], ?Model $post = null): ?FormRequest
    {
        if (! class_exists($class) || ! is_subclass_of($class, FormRequest::class)) {
            return null;
        }

        try {
            $current = $this->container->bound('request') ? $this->container->make('request') : Request::create('/');

            /** @var FormRequest $request */
            $request = $class::createFrom($current instanceof Request ? $current : Request::create('/'));
            $request->setContainer($this->container);
            $request->setUserResolver(fn () => $current instanceof Request ? $current->user() : null);
            $request->replace($input);

            if ($post !== null) {
                $request->setRouteResolver(fn () => new class($post)
                {
                    public function __construct(protected Model $post) {}

                    public function parameter(string $name, mixed $default = null): mixed
                    {
                        return $this->post;
                    }

                    /**
                     * @return array<string, Model>
                     */
                    public function parameters(): array
                    {
                        return ['post' => $this->post];
                    }
                });
            }

            return $request;
        } catch (Throwable) {
            return null;
        }
    }
}
