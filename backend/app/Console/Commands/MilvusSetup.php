<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class MilvusSetup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'milvus:setup 
                            {--check : Only check current status}
                            {--create-collection : Create collection and index}
                            {--reset : Reset and recreate collection}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup and configure Milvus vector database for production';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Milvus Production Setup');
        $this->info('');

        // Check current configuration
        $config = $this->checkConfiguration();
        
        if (!$config['valid']) {
            return Command::FAILURE;
        }

        if ($this->option('check')) {
            return $this->checkStatus();
        }

        if ($this->option('reset')) {
            return $this->resetCollection();
        }

        if ($this->option('create-collection')) {
            return $this->createCollection();
        }

        // Default: full setup
        return $this->fullSetup();
    }

    private function checkConfiguration(): array
    {
        $this->info('🔍 Checking Milvus configuration...');

        $config = config('rag.vector.milvus');
        $host = $config['host'];
        $port = $config['port'];
        $collection = $config['collection'];

        $this->table(['Setting', 'Value'], [
            ['Host', $host],
            ['Port', $port],
            ['Collection', $collection],
            ['TLS', $config['tls'] ? 'Enabled' : 'Disabled'],
            ['Partitions', $config['partitions_enabled'] ? 'Enabled' : 'Disabled'],
            ['Index Type', $config['index']['type']],
            ['Embedding Dim', config('rag.embedding_dim')],
        ]);

        // Check if Python is available
        $pythonPath = $config['python_path'];
        $pythonCheck = Process::run("{$pythonPath} --version");
        
        if (!$pythonCheck->successful()) {
            $this->error("❌ Python not found at: {$pythonPath}");
            $this->error("   Install Python or update MILVUS_PYTHON_PATH in .env");
            return ['valid' => false];
        }

        $this->info("✅ Python found: " . trim($pythonCheck->output()));

        // Check if required Python packages are installed
        $packagesCheck = Process::run("{$pythonPath} -c 'import pymilvus; print(\"PyMilvus:\", pymilvus.__version__)'");
        
        if (!$packagesCheck->successful()) {
            $this->error("❌ PyMilvus package not found");
            $this->error("   Install with: {$pythonPath} -m pip install pymilvus");
            return ['valid' => false];
        }

        $this->info("✅ " . trim($packagesCheck->output()));

        return ['valid' => true, 'config' => $config];
    }

    private function checkStatus(): int
    {
        $this->info('📊 Checking Milvus server status...');

        $scriptPath = $this->createHealthCheckScript();
        $config = config('rag.vector.milvus');
        $pythonPath = $config['python_path'];

        $result = Process::run("{$pythonPath} {$scriptPath}");

        if ($result->successful()) {
            $this->info("✅ Milvus server is running and accessible");
            $this->info($result->output());
        } else {
            $this->error("❌ Milvus connection failed:");
            $this->error($result->errorOutput());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function createCollection(): int
    {
        $this->info('🏗️ Creating Milvus collection and index...');

        if (!$this->option('force')) {
            if (!$this->confirm('This will create the collection. Continue?')) {
                $this->info('❌ Operation cancelled.');
                return Command::FAILURE;
            }
        }

        $scriptPath = $this->createCollectionScript();
        $config = config('rag.vector.milvus');
        $pythonPath = $config['python_path'];

        $result = Process::run("{$pythonPath} {$scriptPath}");

        if ($result->successful()) {
            $this->info("✅ Collection created successfully");
            $this->info($result->output());
        } else {
            $this->error("❌ Collection creation failed:");
            $this->error($result->errorOutput());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function resetCollection(): int
    {
        $this->warn('⚠️  This will DELETE all data in the Milvus collection!');
        
        if (!$this->option('force')) {
            if (!$this->confirm('Are you ABSOLUTELY sure you want to reset the collection?')) {
                $this->info('❌ Operation cancelled.');
                return Command::FAILURE;
            }
        }

        $scriptPath = $this->createResetScript();
        $config = config('rag.vector.milvus');
        $pythonPath = $config['python_path'];

        $result = Process::run("{$pythonPath} {$scriptPath}");

        if ($result->successful()) {
            $this->info("✅ Collection reset successfully");
            $this->info($result->output());
        } else {
            $this->error("❌ Collection reset failed:");
            $this->error($result->errorOutput());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function fullSetup(): int
    {
        $this->info('🚀 Starting full Milvus setup...');

        // 1. Check status
        $this->info('📊 Step 1: Checking server status...');
        if ($this->checkStatus() !== Command::SUCCESS) {
            return Command::FAILURE;
        }

        // 2. Create collection
        $this->info('🏗️ Step 2: Creating collection...');
        if ($this->createCollection() !== Command::SUCCESS) {
            return Command::FAILURE;
        }

        $this->info('');
        $this->info('🎉 Milvus setup completed successfully!');
        $this->info('');
        $this->warn('📝 Next steps:');
        $this->warn('  • Upload documents via admin panel');
        $this->warn('  • Test RAG with: php artisan admin:test-rag');
        $this->warn('  • Monitor with: php artisan milvus:setup --check');

        return Command::SUCCESS;
    }

    private function createHealthCheckScript(): string
    {
        $config = config('rag.vector.milvus');
        
        // Assicurati che la directory esista
        $storageDir = storage_path('app');
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        
        $scriptPath = storage_path('app/milvus_health_check.py');

        $script = <<<PYTHON
#!/usr/bin/env python3
import sys
from pymilvus import connections, utility, Collection

def main():
    try:
        # Connection parameters
        host = "{$config['host']}"
        port = {$config['port']}
        
        print(f"Connecting to Milvus at {host}:{port}...")
        
        # Connect to Milvus
        connections.connect("default", host=host, port=port)
        
        # Check if server is healthy
        print(f"✅ Connected successfully!")
        
        # List collections
        collections = utility.list_collections()
        print(f"📂 Collections: {collections}")
        
        # Check specific collection if exists
        collection_name = "{$config['collection']}"
        if collection_name in collections:
            collection = Collection(collection_name)
            print(f"📊 Collection '{collection_name}' stats:")
            print(f"   • Entities: {collection.num_entities}")
            print(f"   • Schema: {collection.schema}")
        else:
            print(f"⚠️  Collection '{collection_name}' not found")
            
    except Exception as e:
        print(f"❌ Error: {str(e)}")
        sys.exit(1)
        
    finally:
        connections.disconnect("default")

if __name__ == "__main__":
    main()
PYTHON;

        file_put_contents($scriptPath, $script);
        return $scriptPath;
    }

    private function createCollectionScript(): string
    {
        $config = config('rag.vector.milvus');
        
        // Assicurati che la directory esista
        $storageDir = storage_path('app');
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        
        $scriptPath = storage_path('app/milvus_create_collection.py');

        $embeddingDim = config('rag.embedding_dim');
        $indexType = $config['index']['type'];
        $indexParams = json_encode($config['index']['params']);

        $script = <<<PYTHON
#!/usr/bin/env python3
import sys
from pymilvus import connections, FieldSchema, CollectionSchema, DataType, Collection, utility

def main():
    try:
        # Connection parameters
        host = "{$config['host']}"
        port = {$config['port']}
        collection_name = "{$config['collection']}"
        
        print(f"Connecting to Milvus at {host}:{port}...")
        
        # Connect to Milvus
        connections.connect("default", host=host, port=port)
        
        # Check if collection already exists
        if utility.has_collection(collection_name):
            print(f"⚠️  Collection '{collection_name}' already exists")
            collection = Collection(collection_name)
            print(f"📊 Current entities: {collection.num_entities}")
            return
        
        # Define collection schema
        fields = [
            FieldSchema(name="id", dtype=DataType.INT64, is_primary=True, auto_id=True),
            FieldSchema(name="tenant_id", dtype=DataType.INT64),
            FieldSchema(name="kb_id", dtype=DataType.INT64),
            FieldSchema(name="document_id", dtype=DataType.INT64),
            FieldSchema(name="chunk_index", dtype=DataType.INT64),
            FieldSchema(name="content", dtype=DataType.VARCHAR, max_length=65535),
            FieldSchema(name="embedding", dtype=DataType.FLOAT_VECTOR, dim={$embeddingDim}),
            FieldSchema(name="created_at", dtype=DataType.VARCHAR, max_length=50),
        ]
        
        schema = CollectionSchema(fields, "ChatBot Platform KB Chunks")
        
        print(f"🏗️ Creating collection '{collection_name}'...")
        collection = Collection(collection_name, schema)
        
        print(f"✅ Collection created successfully!")
        
        # Create index on embedding field
        index_params = {
            "metric_type": "COSINE",
            "index_type": "{$indexType}",
            "params": {$indexParams}
        }
        
        print(f"🔧 Creating index...")
        collection.create_index("embedding", index_params)
        
        print(f"✅ Index created successfully!")
        
        # Load collection
        collection.load()
        print(f"✅ Collection loaded and ready!")
        
    except Exception as e:
        print(f"❌ Error: {str(e)}")
        sys.exit(1)
        
    finally:
        connections.disconnect("default")

if __name__ == "__main__":
    main()
PYTHON;

        file_put_contents($scriptPath, $script);
        return $scriptPath;
    }

    private function createResetScript(): string
    {
        $config = config('rag.vector.milvus');
        
        // Assicurati che la directory esista
        $storageDir = storage_path('app');
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        
        $scriptPath = storage_path('app/milvus_reset_collection.py');

        $script = <<<PYTHON
#!/usr/bin/env python3
import sys
from pymilvus import connections, utility, Collection

def main():
    try:
        # Connection parameters
        host = "{$config['host']}"
        port = {$config['port']}
        collection_name = "{$config['collection']}"
        
        print(f"Connecting to Milvus at {host}:{port}...")
        
        # Connect to Milvus
        connections.connect("default", host=host, port=port)
        
        # Check if collection exists
        if not utility.has_collection(collection_name):
            print(f"⚠️  Collection '{collection_name}' does not exist")
            return
        
        # Drop collection
        print(f"🗑️ Dropping collection '{collection_name}'...")
        utility.drop_collection(collection_name)
        
        print(f"✅ Collection reset successfully!")
        
    except Exception as e:
        print(f"❌ Error: {str(e)}")
        sys.exit(1)
        
    finally:
        connections.disconnect("default")

if __name__ == "__main__":
    main()
PYTHON;

        file_put_contents($scriptPath, $script);
        return $scriptPath;
    }
}
