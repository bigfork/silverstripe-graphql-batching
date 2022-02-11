# Silverstripe GraphQL batching

Adds basic support for using Apollo’s [`BatchHTTPLink`](https://www.apollographql.com/docs/react/api/link/apollo-link-batch-http/)
to send multiple GraphQL operations in a single HTTP request.

Note that this approach does not run operations in parallel - they are executed in order, one at a time. Because of
this, and the fact that all operations have to be complete before any can be returned, there’s no guarantee this will
improve the performance of your app - do your own research and testing!

## Install

`composer require bigfork/silverstripe-graphql-batching`

## Usage

Register a new Injector service for your schema (in the example below, we’re using the schema name `default`) and then
point your GraphQL route to it:

```yml
SilverStripe\Core\Injector\Injector:
  Bigfork\SilverstripeGraphQLBatching\Controller.default:
    class: Bigfork\SilverstripeGraphQLBatching\Controller
    constructor:
      schema: default
      handler: '%$SilverStripe\GraphQL\QueryHandler\QueryHandlerInterface.default'
      batchMax: 10
SilverStripe\Control\Director:
  rules:
    'graphql': '%$Bigfork\SilverstripeGraphQLBatching\Controller.default'
```

**Please note:** `batchMax` is the maximum number of operations that can be included in a single HTTP request. It should
match the `batchMax` value you set when creating the `BatchHttpLink` in your client-side code (default 10) and should be
kept as low as possible. The higher this value is, the more likely it is to become a DDoS attack vector: if you allow
someone to run dozens of GraphQL operations in a single HTTP request, it becomes trivial to overload your server.
