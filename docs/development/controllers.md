# Controller Pattern

Controllers should:
- Be in `src/Controller/`
- Use **constructor dependency injection**
- Use **Symfony Request objects** (never access $_GET, $_POST, $_SERVER directly)
- Return **Symfony Response objects** (never void)
- Have a `dispatch()` method that routes actions
- Throw **custom exceptions** (never die/exit)

## Controller Example

```php
<?php

namespace OpenCoreEMR\Modules\{ModuleName}\Controller;

use OpenCoreEMR\Modules\{ModuleName}\Exception\{Module}AccessDeniedException;
use OpenCoreEMR\Modules\{ModuleName}\Exception\{Module}NotFoundException;
use OpenCoreEMR\Modules\{ModuleName}\Exception\{Module}ValidationException;
use OpenCoreEMR\Modules\{ModuleName}\GlobalConfig;
use OpenCoreEMR\Modules\{ModuleName}\Service\{Feature}Service;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class {Feature}Controller
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly {Feature}Service $service,
        private readonly Environment $twig
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Dispatch action to appropriate method
     */
    public function dispatch(string $action): Response
    {
        $request = Request::createFromGlobals();

        return match ($action) {
            'create' => $this->handleCreate($request),
            'view' => $this->showView($request),
            'list' => $this->showList($request),
            default => $this->showList($request),
        };
    }

    private function showList(Request $request): Response
    {
        // Access query parameters
        $filter = $request->query->get('filter', '');

        // Business logic here

        $content = $this->twig->render('{feature}/list.html.twig', [
            'items' => $items,
            'csrf_token' => CsrfUtils::collectCsrfToken(),
        ]);

        return new Response($content);
    }

    private function handleCreate(Request $request): Response
    {
        // Check HTTP method
        if (!$request->isMethod('POST')) {
            return new RedirectResponse($request->getPathInfo());
        }

        // Validate CSRF
        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''))) {
            throw new {Module}AccessDeniedException("CSRF token verification failed");
        }

        // Access POST data with type casting
        $name = (string)$request->request->get('name', '');

        // Validate input
        if (empty($name)) {
            throw new {Module}ValidationException("Name is required");
        }

        // Process request
        try {
            $this->service->create(['name' => $name]);
            return new RedirectResponse($request->getPathInfo());
        } catch (\Throwable $e) {
            $this->logger->error("Error creating item: " . $e->getMessage());
            throw new {Module}Exception("Error creating item: " . $e->getMessage());
        }
    }
}
```

## Request/Response Handling - CRITICAL RULES

### ALWAYS DO:
- Use `Request::createFromGlobals()` in controller dispatch method
- Access request data via `$request->request->get()` (POST), `$request->query->get()` (GET), `$request->files->get()` (uploads)
- Use `$request->isMethod('POST')` instead of checking `$_SERVER['REQUEST_METHOD']`
- **Use `$_SESSION` directly for session access** (Symfony sessions not available yet)
- Cast request values: `(string)$request->request->get('field', '')`
- Controllers return `Response`, `JsonResponse`, `RedirectResponse`, or `BinaryFileResponse`
- Use Symfony HTTP Foundation components
- Call `$response->send()` in public entry points
- Use `Response` constants: `Response::HTTP_OK`, `Response::HTTP_NOT_FOUND`, etc.
- Throw exceptions with proper types (never with status codes in constructor)

### NEVER DO:
- ~~`$GLOBALS['kernel']`~~ → Use `$globalsAccessor->get('kernel')`
- ~~`$_GET['field']`~~ → Use `$request->query->get('field')`
- ~~`$_POST['field']`~~ → Use `$request->request->get('field')`
- ~~`$_FILES['file']`~~ → Use `$request->files->get('file')`
- ~~`$_SERVER['REQUEST_METHOD']`~~ → Use `$request->isMethod('POST')`
- ~~`$request->getSession()`~~ → **Use native `$_SESSION` instead** (Symfony sessions not available)
- ~~`header('Location: ...')`~~ → Use `RedirectResponse`
- ~~`http_response_code(404)`~~ → Use `new Response($content, 404)` or exceptions
- ~~`echo json_encode($data)`~~ → Use `JsonResponse`
- ~~`readfile($path)`~~ → Use `BinaryFileResponse`
- ~~`die()` or `exit`~~ → Throw exceptions
- ~~Controllers returning `void`~~ → Return `Response` objects

## Example: Correct Request/Response Handling

```php
private function handleForm(Request $request): Response
{
    // Check HTTP method
    if (!$request->isMethod('POST')) {
        return new RedirectResponse($request->getPathInfo());
    }

    // Get POST data
    $name = (string)$request->request->get('name', '');
    $email = (string)$request->request->get('email', '');

    // Get uploaded file
    $uploadedFile = $request->files->get('document');
    if ($uploadedFile && $uploadedFile->isValid()) {
        $filePath = $uploadedFile->getPathname();
    }

    // Get query parameters
    $filter = $request->query->get('filter');

    // Session access (use $_SESSION directly)
    $userId = $_SESSION['authUserID'] ?? null;

    // JSON Response
    return new JsonResponse(['status' => 'success'], Response::HTTP_OK);

    // Redirect
    return new RedirectResponse($request->getPathInfo());

    // File Download
    $response = new BinaryFileResponse($filePath);
    $response->setContentDisposition(
        ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        'filename.pdf'
    );
    return $response;

    // HTML Response
    $content = $this->twig->render('template.html.twig', $data);
    return new Response($content);
}
```
