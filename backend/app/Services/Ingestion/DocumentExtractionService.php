<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Contracts\Ingestion\DocumentExtractionServiceInterface;
use App\Exceptions\ExtractionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Service for extracting text from various document formats
 * 
 * Supports: PDF, DOCX, XLSX, PPTX, TXT, Markdown
 * 
 * @package App\Services\Ingestion
 */
class DocumentExtractionService implements DocumentExtractionServiceInterface
{
    private const SUPPORTED_FORMATS = ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'txt', 'md'];
    
    /**
     * {@inheritDoc}
     */
    public function extractText(string $filePath): string
    {
        if ($filePath === '' || !Storage::disk('public')->exists($filePath)) {
            throw new ExtractionException("File not found: {$filePath}");
        }
        
        $format = $this->detectFormat($filePath);
        
        Log::debug('extraction.start', [
            'file_path' => $filePath,
            'format' => $format
        ]);
        
        try {
            $text = match ($format) {
                'pdf' => $this->extractFromPdf($filePath),
                'docx', 'doc' => $this->extractFromDocx($filePath),
                'xlsx', 'xls' => $this->extractFromXlsx($filePath),
                'pptx', 'ppt' => $this->extractFromPptx($filePath),
                'txt', 'md' => $this->extractFromPlainText($filePath),
                default => throw new ExtractionException("Unsupported format: {$format}")
            };
            
            Log::debug('extraction.success', [
                'file_path' => $filePath,
                'text_length' => strlen($text)
            ]);
            
            return $text;
        } catch (\Throwable $e) {
            Log::error('extraction.failed', [
                'file_path' => $filePath,
                'format' => $format,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new ExtractionException(
                "Failed to extract text from {$format} file: " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * {@inheritDoc}
     */
    public function detectFormat(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if (!in_array($extension, self::SUPPORTED_FORMATS, true)) {
            throw new ExtractionException("Unsupported file extension: {$extension}");
        }
        
        return $extension;
    }
    
    /**
     * {@inheritDoc}
     */
    public function isFormatSupported(string $format): bool
    {
        return in_array(strtolower($format), self::SUPPORTED_FORMATS, true);
    }
    
    /**
     * Extract text from PDF file
     * 
     * @param string $filePath Storage path
     * @return string Extracted text
     * @throws ExtractionException
     */
    private function extractFromPdf(string $filePath): string
    {
        $raw = Storage::disk('public')->get($filePath);
        $parser = new PdfParser();
        $pdf = $parser->parseContent($raw);
        
        return (string) $pdf->getText();
    }
    
    /**
     * Extract text from DOCX/DOC file
     * 
     * @param string $filePath Storage path
     * @return string Extracted text
     * @throws ExtractionException
     */
    private function extractFromDocx(string $filePath): string
    {
        $fullPath = Storage::disk('public')->path($filePath);
        $phpWord = PhpWordIOFactory::load($fullPath);
        $text = '';
        
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . ' ';
                } elseif (method_exists($element, 'getElements')) {
                    // Handle complex elements (tables, lists, etc.)
                    $text .= $this->extractTextFromComplexElement($element) . ' ';
                }
            }
        }
        
        return trim($text);
    }
    
    /**
     * Extract text from XLSX/XLS file
     * 
     * @param string $filePath Storage path
     * @return string Extracted text
     * @throws ExtractionException
     */
    private function extractFromXlsx(string $filePath): string
    {
        $fullPath = Storage::disk('public')->path($filePath);
        $spreadsheet = SpreadsheetIOFactory::load($fullPath);
        $text = '';
        
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $text .= "Sheet: " . $sheet->getTitle() . "\n\n";
            
            foreach ($sheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                
                $rowValues = [];
                foreach ($cellIterator as $cell) {
                    $rowValues[] = $cell->getValue();
                }
                
                $text .= implode("\t", $rowValues) . "\n";
            }
            
            $text .= "\n";
        }
        
        return trim($text);
    }
    
    /**
     * Extract text from PPTX/PPT file
     * 
     * @param string $filePath Storage path
     * @return string Extracted text
     * @throws ExtractionException
     */
    private function extractFromPptx(string $filePath): string
    {
        // PhpOffice\PhpPresentation support for PowerPoint
        // For now, return empty string as placeholder
        // TODO: Implement when PhpPresentation is added to dependencies
        Log::warning('extraction.pptx_not_implemented', ['file_path' => $filePath]);
        return '';
    }
    
    /**
     * Extract text from plain text file (TXT, MD)
     * 
     * @param string $filePath Storage path
     * @return string Extracted text
     */
    private function extractFromPlainText(string $filePath): string
    {
        return (string) Storage::disk('public')->get($filePath);
    }
    
    /**
     * Recursively extract text from complex Word elements
     * 
     * @param mixed $element PhpWord element
     * @return string Extracted text
     */
    private function extractTextFromComplexElement($element): string
    {
        $text = '';
        
        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $subElement) {
                if (method_exists($subElement, 'getText')) {
                    $text .= $subElement->getText() . ' ';
                } elseif (method_exists($subElement, 'getElements')) {
                    $text .= $this->extractTextFromComplexElement($subElement) . ' ';
                }
            }
        }
        
        return $text;
    }
}

