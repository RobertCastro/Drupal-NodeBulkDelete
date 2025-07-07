<?php

namespace Drupal\node_bulk_delete\Service;

/**
 * Servicio para la consulta y eliminación masiva de nodos.
 */
class NodeBulkDeleteService {
  // Métodos de lógica se implementarán en el siguiente paso.

  /**
   * Obtiene el número de nodos actuales y los que se eliminarían.
   *
   * @param string $content_type
   * @param int|null $start
   * @param int|null $end
   * @return array
   *   ['total' => int, 'to_delete' => int]
   */
  public function getNodeCounts($content_type, $start = NULL, $end = NULL) {
    $counts = [
      'total' => 0,
      'to_delete' => 0,
    ];
    if ($content_type) {
      $query = \Drupal::entityQuery('node')
        ->condition('type', $content_type)
        ->accessCheck(TRUE);
      $counts['total'] = $query->count()->execute();
      if ($start && $end) {
        $query = \Drupal::entityQuery('node')
          ->condition('type', $content_type)
          ->condition('created', $start, ">=")
          ->condition('created', $end, "<=")
          ->accessCheck(TRUE);
        $counts['to_delete'] = $query->count()->execute();
      }
    }
    return $counts;
  }

  /**
   * Obtiene información detallada de los nodos a eliminar.
   *
   * @param string $content_type
   * @param int $start
   * @param int $end
   * @return array
   *   Array con información de nodos ['nid' => int, 'path' => string]
   */
  public function getNodesInfo($content_type, $start, $end) {
    if ($content_type === 'noticia') {
      return [];
    }
    if (!$start || !$end) {
      return [];
    }
    
    $query = \Drupal::entityQuery('node')
      ->condition('type', $content_type)
      ->condition('created', $start, ">=")
      ->condition('created', $end, "<=")
      ->accessCheck(TRUE);
    $nids = $query->execute();
    
    if (empty($nids)) {
      return [];
    }
    
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $nodes = $storage->loadMultiple($nids);
    $nodes_info = [];
    
    foreach ($nodes as $node) {
      $nodes_info[] = [
        'nid' => $node->id(),
        'path' => \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $node->id()),
      ];
    }
    
    return $nodes_info;
  }

  /**
   * Elimina los nodos según los filtros dados.
   *
   * @param string $content_type
   * @param int $start
   * @param int $end
   * @return int
   *   Número de nodos eliminados.
   */
  public function deleteNodes($content_type, $start, $end) {
    if ($content_type === 'noticia') {
      return 0;
    }
    if (!$start || !$end) {
      return 0;
    }
    $query = \Drupal::entityQuery('node')
      ->condition('type', $content_type)
      ->condition('created', $start, ">=")
      ->condition('created', $end, "<=")
      ->accessCheck(TRUE);
    $nids = $query->execute();
    if (empty($nids)) {
      return 0;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $nodes = $storage->loadMultiple($nids);
    $count = count($nodes);
    $storage->delete($nodes);
    return $count;
  }
} 