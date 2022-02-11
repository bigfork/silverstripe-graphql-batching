<?php

namespace Bigfork\SilverstripeGraphQLBatching;

use BadMethodCallException;
use Exception;
use GraphQL\Language\Parser;
use GraphQL\Language\Source;
use GraphQL\Type\Schema;
use Psr\Container\NotFoundExceptionInterface;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\EventDispatcher\Dispatch\Dispatcher;
use SilverStripe\EventDispatcher\Symfony\Event;
use SilverStripe\GraphQL\Controller as GraphQLController;
use SilverStripe\GraphQL\PersistedQuery\RequestProcessor;
use SilverStripe\GraphQL\QueryHandler\QueryHandler;
use SilverStripe\GraphQL\QueryHandler\QueryHandlerInterface;
use SilverStripe\GraphQL\Schema\Exception\EmptySchemaException;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Exception\SchemaNotFoundException;
use SilverStripe\GraphQL\Schema\SchemaBuilder;
use SilverStripe\ORM\ArrayLib;
use SilverStripe\Versioned\Versioned;

class Controller extends GraphQLController
{
    private string $schemaKey;

    private QueryHandlerInterface $queryHandler;

    protected int $batchMax;

    public function __construct(
        ?string $schemaKey = null,
        ?QueryHandlerInterface $queryHandler = null,
        ?int $batchMax = null
    ) {
        parent::__construct($schemaKey, $queryHandler);
        $this->setBatchMax($batchMax ?? 10);
    }

    /**
     * @throws BadMethodCallException
     * @throws HTTPResponse_Exception
     * @throws NotFoundExceptionInterface
     */
    public function index(HTTPRequest $request): HTTPResponse
    {
        if (!$this->schemaKey) {
            throw new BadMethodCallException('Cannot query the controller without a schema key defined');
        }

        $stage = $request->param('Stage');
        if (class_exists(Versioned::class) && $stage) {
            Versioned::set_stage($stage);
        }

        // Check for a possible CORS preflight request and handle if necessary
        if ($request->httpMethod() === 'OPTIONS') {
            return $this->handleOptions($request);
        }

        // Grab a list of queries from the request
        $queryList = $this->getQueriesFromRequest($request);
        if (empty($queryList)) {
            $this->httpError(400, 'This endpoint requires a "query" parameter');
        }

        $queryCount = count($queryList);
        if ($queryCount > $this->getBatchMax()) {
            $this->httpError(400, 'Maximum number of batched operations exceeded');
        }

        // Process either a single query, or an array (aka batch) of queries
        if ($queryCount === 1) {
            [$query, $variables] = $queryList[0];
            $result = $this->processQuery($query, $variables);
        } else {
            $result = [];
            foreach ($queryList as $queryData) {
                [$query, $variables] = $queryData;
                $result[] = $this->processQuery($query, $variables);
            }
        }

        $response = $this->addCorsHeaders($request, new HTTPResponse(json_encode($result)));
        return $response->addHeader('Content-Type', 'application/json');
    }

    /**
     * Get a list of queries from the request. JSON requests may contain one or many queries,
     * non-JSON requests contain a single query
     *
     * @param HTTPRequest $request
     * @return array|array[]
     * @throws NotFoundExceptionInterface
     */
    protected function getQueriesFromRequest(HTTPRequest $request): array
    {
        $contentType = $request->getHeader('content-type');
        $isJson = preg_match('#^application/json\b#', $contentType);
        if (!$isJson) {
            /** @var RequestProcessor $persistedProcessor  */
            $persistedProcessor = Injector::inst()->get(RequestProcessor::class);
            [$query, $variables] = $persistedProcessor->getRequestQueryVariables($request);
            // No query found, return an empty result
            if (!$query) {
                return [];
            }

            // Return a single query when data is provided in a non-json format
            return [
                [$query, (array)$variables]
            ];
        }

        $rawBody = $request->getBody();
        $data = json_decode($rawBody ?: '', true);
        // No queries found, so return an empty result
        if (!is_array($data)) {
            return [];
        }

        // An associative array is a request containing a single query
        if (ArrayLib::is_associative($data)) {
            $query = $data['query'] ?? null;
            $variables = $data['variables'] ?? [];
            return [
                [$query, (array)$variables]
            ];
        }

        // An indexed array is a batch of queries, so extract the relevant data from each of them
        $queries = [];
        foreach ($data as $queryData) {
            $query = $queryData['query'] ?? null;
            $variables = $queryData['variables'] ?? [];
            $queries[] = [$query, (array)$variables];
        }

        return $queries;
    }

    /**
     * @param string $query
     * @param array $variables
     * @return array
     */
    protected function processQuery(string $query, array $variables = []): array
    {
        try {
            $graphqlSchema = $this->getSchema();
            $handler = $this->getQueryHandler();
            $this->applyContext($handler);
            $queryDocument = Parser::parse(new Source($query));
            $ctx = $handler->getContext();
            $result = $handler->query($graphqlSchema, $query, $variables);

            // Fire an event
            $eventContext = [
                'schema' => $graphqlSchema,
                'schemaKey' => $this->getSchemaKey(),
                'query' => $query,
                'context' => $ctx,
                'variables' => $variables,
                'result' => $result,
            ];
            $event = QueryHandler::isMutation($query) ? 'graphqlMutation' : 'graphqlQuery';
            $operationName = QueryHandler::getOperationName($queryDocument);
            Dispatcher::singleton()->trigger($event, Event::create($operationName, $eventContext));
        } catch (Exception $exception) {
            $error = ['message' => $exception->getMessage()];

            if (Director::isDev()) {
                $error['code'] = $exception->getCode();
                $error['file'] = $exception->getFile();
                $error['line'] = $exception->getLine();
                $error['trace'] = $exception->getTrace();
            }

            $result = [
                'errors' => [$error]
            ];
        }

        return $result;
    }

    /**
     * @throws SchemaBuilderException
     * @throws EmptySchemaException
     * @throws SchemaNotFoundException
     */
    protected function getSchema(): Schema
    {
        $builder = SchemaBuilder::singleton();
        $graphqlSchema = $builder->getSchema($this->getSchemaKey());
        if (!$graphqlSchema && $this->autobuildEnabled()) {
            $graphqlSchema = $builder->buildByName($this->getSchemaKey(), true);
        } elseif (!$graphqlSchema) {
            throw new SchemaBuilderException(sprintf(
                'Schema %s has not been built.',
                $this->getSchemaKey()
            ));
        }

        return $graphqlSchema;
    }

    public function setSchemaKey(string $schemaKey): self
    {
        $this->schemaKey = $schemaKey;
        return $this;
    }

    public function getSchemaKey(): ?string
    {
        return $this->schemaKey;
    }

    public function getQueryHandler(): QueryHandlerInterface
    {
        return $this->queryHandler;
    }

    public function setQueryHandler(QueryHandlerInterface $queryHandler): self
    {
        $this->queryHandler = $queryHandler;
        return $this;
    }

    public function getBatchMax(): int
    {
        return $this->batchMax;
    }

    public function setBatchMax(int $batchMax): self
    {
        $this->batchMax = $batchMax;
        return $this;
    }
}
