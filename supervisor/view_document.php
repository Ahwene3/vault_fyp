<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('supervisor');

/**
 * Extract readable paragraph text from a DOCX file for in-app preview.
 * This keeps preview local without external services and falls back cleanly.
 */
function extract_docx_preview_text(string $path, int $maxChars = 12000): array
{
    if (!class_exists('ZipArchive')) {
        return ['', 'DOCX preview is unavailable because ZipArchive is not enabled on the server.'];
    }
    if (!class_exists('DOMDocument')) {
        return ['', 'DOCX preview is unavailable because the DOM XML extension is not enabled on the server.'];
    }
    if (!is_file($path) || !is_readable($path)) {
        return ['', 'Document file is not readable for preview.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['', 'Unable to open DOCX file for preview.'];
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false || $xml === '') {
        return ['', 'No readable content found in this DOCX file.'];
    }

    $dom = new DOMDocument();
    $prevUseInternalErrors = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prevUseInternalErrors);
    if (!$loaded) {
        return ['', 'Unable to parse DOCX text content for preview.'];
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $lines = [];
    $paragraphs = $xpath->query('//w:body/w:p');
    if ($paragraphs !== false) {
        foreach ($paragraphs as $paragraph) {
            $parts = [];
            $textNodes = $xpath->query('.//w:t', $paragraph);
            if ($textNodes !== false) {
                foreach ($textNodes as $node) {
                    $parts[] = $node->textContent;
                }
            }

            $line = trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
            if ($line !== '') {
                $lines[] = $line;
            }
        }
    }

    if (empty($lines)) {
        return ['', 'No visible text content could be extracted from this DOCX file.'];
    }

    $preview = implode("\n\n", $lines);
    if (strlen($preview) > $maxChars) {
        $preview = substr($preview, 0, $maxChars) . "\n\n... [preview truncated]";
    }

    return [$preview, ''];
}

$uid = user_id();
$pdo = getPDO();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    flash('error', 'Invalid document request.');
    redirect(base_url('supervisor/students.php'));
}

$stmt = $pdo->prepare('SELECT pd.id, pd.file_name, pd.file_path, pd.mime_type, pd.document_type, pd.chapter, pd.uploaded_at, p.id AS project_id, p.title AS project_title, p.supervisor_id, u.full_name AS student_name FROM project_documents pd JOIN projects p ON pd.project_id = p.id JOIN users u ON p.student_id = u.id WHERE pd.id = ? AND p.supervisor_id = ? LIMIT 1');
$stmt->execute([$id, $uid]);
$doc = $stmt->fetch();

if (!$doc) {
    flash('error', 'Document not found or access denied.');
    redirect(base_url('supervisor/students.php'));
}

$pageTitle = 'View Document';
require_once __DIR__ . '/../includes/header.php';

$docTypeLabel = ($doc['document_type'] === 'proposal') ? 'Documentation' : ucfirst((string) $doc['document_type']);
$chapterLabel = '';
if (!empty($doc['chapter'])) {
    $chapterLabel = str_replace('chapter', 'Chapter ', (string) $doc['chapter']);
}
$previewUrl = base_url('download.php?id=' . (int) $doc['id'] . '&view=1');
$downloadUrl = base_url('download.php?id=' . (int) $doc['id']);
$isPdf = stripos((string) $doc['mime_type'], 'pdf') !== false;
$extension = strtolower(pathinfo((string) $doc['file_name'], PATHINFO_EXTENSION));
$isDocx = $extension === 'docx' || stripos((string) $doc['mime_type'], 'wordprocessingml') !== false;
$isLegacyDoc = $extension === 'doc';
$docAbsolutePath = __DIR__ . '/../uploads/' . ltrim((string) $doc['file_path'], '/');
$docxPreviewText = '';
$docxPreviewError = '';

if ($isDocx) {
    [$docxPreviewText, $docxPreviewError] = extract_docx_preview_text($docAbsolutePath);
}
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h1 class="mb-1"><?= e($doc['file_name']) ?></h1>
        <p class="text-muted mb-0">
            <strong>Student:</strong> <?= e($doc['student_name']) ?> |
            <strong>Project:</strong> <?= e($doc['project_title']) ?> |
            <strong>Type:</strong> <?= e($docTypeLabel) ?>
            <?php if ($chapterLabel): ?> |
                <strong>Chapter:</strong> <?= e($chapterLabel) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="btn-group">
        <?php if (!$isDocx): ?>
            <a href="<?= e($previewUrl) ?>" class="btn btn-outline-primary" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right"></i> Open Raw View
            </a>
        <?php endif; ?>
        <a href="<?= e($downloadUrl) ?>" class="btn btn-primary">
            <i class="bi bi-download"></i> Download
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">Document Preview</div>
    <div class="card-body">
        <?php if ($isPdf): ?>
            <iframe src="<?= e($previewUrl) ?>" title="Document Preview" style="width: 100%; min-height: 78vh; border: 1px solid #e2e8f0; border-radius: 8px;"></iframe>
        <?php elseif ($isDocx && $docxPreviewText !== ''): ?>
            <div class="alert alert-success mb-3">
                DOCX preview loaded. Formatting, images, and tables may differ from the original document.
            </div>
            <div style="max-height: 78vh; overflow: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; background: #fff; color: #111; white-space: pre-wrap;">
                <?= e($docxPreviewText) ?>
            </div>
            <div class="mt-3">
                <a href="<?= e($downloadUrl) ?>" class="btn btn-primary">Download Original DOCX</a>
            </div>
        <?php elseif ($isDocx): ?>
            <div class="alert alert-warning mb-3">
                <?= e($docxPreviewError !== '' ? $docxPreviewError : 'DOCX preview is currently unavailable.') ?>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e($downloadUrl) ?>" class="btn btn-primary">Download</a>
            </div>
        <?php elseif ($isLegacyDoc): ?>
            <div class="alert alert-info mb-3">
                Legacy .doc files cannot be previewed reliably in-browser on this server. Please download the file.
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e($downloadUrl) ?>" class="btn btn-primary">Download</a>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-3">
                In-browser preview is best for PDF files. For this file type, use the buttons above:
                <strong>Open Raw View</strong> or <strong>Download</strong>.
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e($previewUrl) ?>" class="btn btn-outline-primary" target="_blank" rel="noopener">Open Raw View</a>
                <a href="<?= e($downloadUrl) ?>" class="btn btn-primary">Download</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<p class="mt-3">
    <a href="<?= base_url('supervisor/student_detail.php?pid=' . (int) $doc['project_id']) ?>" class="btn btn-outline-secondary">Back to Student Detail</a>
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
