<?php

/**
 * God Classes Analysis Script
 * 
 * Analyzes three critical God Classes using PHP Reflection API to extract:
 * - Methods, properties, LOC
 * - Dependencies (internal Services, external APIs)
 * - Complexity metrics
 * - External service calls
 * 
 * @author Artiforge Agent - Senior PHP Architect
 * @date 2025-10-14
 */

require __DIR__ . '/../backend/vendor/autoload.php';

use Illuminate\Support\Facades\File;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Classes to analyze
$targetClasses = [
    'App\\Jobs\\IngestUploadedDocumentJob',
    'App\\Http\\Controllers\\Api\\ChatCompletionsController',
    'App\\Http\\Controllers\\Admin\\DocumentAdminController',
];

$results = [];

foreach ($targetClasses as $className) {
    echo "🔍 Analyzing: {$className}\n";
    
    try {
        $analysis = analyzeClass($className);
        $results[$className] = $analysis;
        
        echo "   ✅ Found {$analysis['method_count']} methods, {$analysis['lines_of_code']} LOC\n";
    } catch (\Exception $e) {
        echo "   ❌ Error: {$e->getMessage()}\n";
        $results[$className] = ['error' => $e->getMessage()];
    }
}

// Save results
$outputPath = __DIR__ . '/../backend/storage/temp/god_analysis.json';
@mkdir(dirname($outputPath), 0755, true);
file_put_contents($outputPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n📊 Analysis complete! Results saved to: {$outputPath}\n";
echo "📈 Summary:\n";
foreach ($results as $class => $data) {
    if (isset($data['error'])) {
        echo "   ❌ " . basename(str_replace('\\', '/', $class)) . ": ERROR\n";
    } else {
        echo "   ✅ " . basename(str_replace('\\', '/', $class)) . ": {$data['lines_of_code']} LOC, {$data['method_count']} methods\n";
    }
}

/**
 * Analyze a class using Reflection API
 */
function analyzeClass(string $className): array
{
    $reflection = new ReflectionClass($className);
    $filePath = $reflection->getFileName();
    
    // Count lines of code
    $lines = file($filePath);
    $linesOfCode = count($lines);
    
    // Extract methods
    $methods = [];
    foreach ($reflection->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() === $className) {
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();
            $methodLoc = $endLine - $startLine + 1;
            
            $returnType = 'mixed';
            if ($method->getReturnType()) {
                $rt = $method->getReturnType();
                if ($rt instanceof ReflectionNamedType) {
                    $returnType = $rt->getName();
                } elseif ($rt instanceof ReflectionUnionType) {
                    $returnType = implode('|', array_map(fn($t) => $t->getName(), $rt->getTypes()));
                }
            }
            
            $methods[] = [
                'name' => $method->getName(),
                'visibility' => getVisibility($method),
                'is_static' => $method->isStatic(),
                'lines_of_code' => $methodLoc,
                'parameters' => count($method->getParameters()),
                'return_type' => $returnType,
            ];
        }
    }
    
    // Extract properties
    $properties = [];
    foreach ($reflection->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() === $className) {
            $propertyType = 'mixed';
            if ($property->getType()) {
                $pt = $property->getType();
                if ($pt instanceof ReflectionNamedType) {
                    $propertyType = $pt->getName();
                } elseif ($pt instanceof ReflectionUnionType) {
                    $propertyType = implode('|', array_map(fn($t) => $t->getName(), $pt->getTypes()));
                }
            }
            
            $properties[] = [
                'name' => $property->getName(),
                'visibility' => getVisibility($property),
                'is_static' => $property->isStatic(),
                'type' => $propertyType,
            ];
        }
    }
    
    // Analyze dependencies from constructor
    $constructor = $reflection->getConstructor();
    $dependencies = [];
    if ($constructor) {
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                $dependencies[] = [
                    'name' => $param->getName(),
                    'type' => $typeName,
                    'category' => categorizeDependency($typeName),
                ];
            }
        }
    }
    
    // Analyze external API calls in source code
    $sourceCode = file_get_contents($filePath);
    $externalCalls = analyzeExternalCalls($sourceCode);
    
    // Calculate complexity
    $complexity = calculateComplexity($methods);
    
    // Identify responsibilities
    $responsibilities = identifyResponsibilities($className, $methods, $sourceCode);
    
    return [
        'class_name' => $className,
        'file_path' => $filePath,
        'lines_of_code' => $linesOfCode,
        'method_count' => count($methods),
        'property_count' => count($properties),
        'methods' => $methods,
        'properties' => $properties,
        'dependencies' => $dependencies,
        'external_calls' => $externalCalls,
        'complexity' => $complexity,
        'responsibilities' => $responsibilities,
    ];
}

/**
 * Get visibility (public/protected/private)
 */
function getVisibility($reflectionMember): string
{
    if ($reflectionMember->isPublic()) return 'public';
    if ($reflectionMember->isProtected()) return 'protected';
    if ($reflectionMember->isPrivate()) return 'private';
    return 'unknown';
}

/**
 * Categorize dependency type
 */
function categorizeDependency(string $typeName): string
{
    if (str_contains($typeName, 'OpenAI')) return 'external_api';
    if (str_contains($typeName, 'Milvus')) return 'external_api';
    if (str_contains($typeName, 'Service')) return 'internal_service';
    if (str_contains($typeName, 'Repository')) return 'internal_repository';
    if (str_contains($typeName, 'Client')) return 'external_client';
    if (str_contains($typeName, 'Illuminate')) return 'framework';
    return 'other';
}

/**
 * Analyze external API calls in source code
 */
function analyzeExternalCalls(string $sourceCode): array
{
    $calls = [];
    
    // OpenAI API calls
    if (preg_match_all('/OpenAI.*?->(\w+)\(/i', $sourceCode, $matches)) {
        $calls['OpenAI'] = array_unique($matches[1]);
    }
    
    // Milvus calls
    if (preg_match_all('/Milvus.*?->(\w+)\(/i', $sourceCode, $matches)) {
        $calls['Milvus'] = array_unique($matches[1]);
    }
    
    // Storage/S3 calls
    if (preg_match_all('/Storage::(\w+)\(/i', $sourceCode, $matches)) {
        $calls['Storage'] = array_unique($matches[1]);
    }
    
    // Redis calls
    if (preg_match_all('/Redis::(\w+)\(/i', $sourceCode, $matches)) {
        $calls['Redis'] = array_unique($matches[1]);
    }
    
    // DB queries
    if (preg_match_all('/DB::(\w+)\(/i', $sourceCode, $matches)) {
        $calls['Database'] = array_unique($matches[1]);
    }
    
    return $calls;
}

/**
 * Calculate cyclomatic complexity (simplified)
 */
function calculateComplexity(array $methods): array
{
    $totalComplexity = 0;
    $complexityPerMethod = [];
    
    foreach ($methods as $method) {
        // Simplified: LOC / 10 as proxy for complexity
        $methodComplexity = max(1, (int)($method['lines_of_code'] / 10));
        $totalComplexity += $methodComplexity;
        $complexityPerMethod[$method['name']] = $methodComplexity;
    }
    
    return [
        'total' => $totalComplexity,
        'average' => count($methods) > 0 ? round($totalComplexity / count($methods), 2) : 0,
        'per_method' => $complexityPerMethod,
    ];
}

/**
 * Identify responsibilities based on method names and patterns
 */
function identifyResponsibilities(string $className, array $methods, string $sourceCode): array
{
    $responsibilities = [];
    
    // Common patterns for different responsibilities
    $patterns = [
        'File Extraction' => ['extract', 'read', 'parse', 'pdf', 'docx'],
        'Text Processing' => ['normalize', 'clean', 'sanitize', 'transform'],
        'Chunking' => ['chunk', 'split', 'segment', 'divide'],
        'Embeddings' => ['embed', 'vector', 'embedding'],
        'Vector Indexing' => ['index', 'upsert', 'milvus', 'insert'],
        'Storage' => ['store', 'save', 'upload', 's3', 'storage'],
        'RAG Orchestration' => ['retrieve', 'search', 'context', 'rag'],
        'Fallback Logic' => ['fallback', 'retry', 'error'],
        'Profiling' => ['profile', 'metric', 'log', 'track'],
        'CRUD Operations' => ['create', 'read', 'update', 'delete', 'destroy'],
        'Filtering' => ['filter', 'search', 'query', 'where'],
        'Validation' => ['validate', 'check', 'verify'],
    ];
    
    foreach ($patterns as $responsibility => $keywords) {
        $found = false;
        
        // Check method names
        foreach ($methods as $method) {
            foreach ($keywords as $keyword) {
                if (stripos($method['name'], $keyword) !== false) {
                    $found = true;
                    break 2;
                }
            }
        }
        
        // Check source code
        if (!$found) {
            foreach ($keywords as $keyword) {
                if (stripos($sourceCode, $keyword) !== false) {
                    $found = true;
                    break;
                }
            }
        }
        
        if ($found) {
            $responsibilities[] = $responsibility;
        }
    }
    
    return $responsibilities;
}

