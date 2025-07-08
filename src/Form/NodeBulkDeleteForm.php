<?php

namespace Drupal\node_bulk_delete\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Datetime\Element\DateTime;
use Drupal\Core\Datetime\DrupalDateTime;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node_bulk_delete\Service\NodeBulkDeleteService;

/**
 * Formulario para la eliminación masiva de nodos.
 */
class NodeBulkDeleteForm extends FormBase {

  /**
   * @var \Drupal\node_bulk_delete\Service\NodeBulkDeleteService
   */
  protected $bulkDeleteService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('node_bulk_delete.service')
    );
  }

  /**
   * Constructor.
   */
  public function __construct(NodeBulkDeleteService $bulkDeleteService = NULL) {
    $this->bulkDeleteService = $bulkDeleteService;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'node_bulk_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Obtener tipos de contenido disponibles, excluyendo 'noticias'.
    $content_types = NodeType::loadMultiple();
    $options = [];
    foreach ($content_types as $type) {
      if ($type->id() !== 'noticias') {
        $options[$type->id()] = $type->label();
      }
    }

    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Tipo de contenido'),
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Seleccione -'),
      '#ajax' => [
        'callback' => '::updateNodeCount',
        'event' => 'change',
        'wrapper' => 'node-count-wrapper',
      ],
    ];

    $form['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Fecha de inicio'),
      '#required' => TRUE,
      '#default_value' => '2025-06-01',
      '#ajax' => [
        'callback' => '::updateNodeCount',
        'event' => 'change',
        'wrapper' => 'node-count-wrapper',
      ],
    ];
    $form['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Fecha de fin'),
      '#required' => TRUE,
      '#default_value' => '2025-08-28',
      '#ajax' => [
        'callback' => '::updateNodeCount',
        'event' => 'change',
        'wrapper' => 'node-count-wrapper',
      ],
    ];

    // Mostrar el número de nodos actuales y los que se eliminarán.
    $form['node_count'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'node-count-wrapper'],
    ];
    $counts = $this->getNodeCounts($form_state);
    $form['node_count']['current'] = [
      '#markup' => '<div><strong>' . $this->t('Nodos actuales:') . '</strong> ' . $counts['total'] . '</div>',
    ];
    $form['node_count']['to_delete'] = [
      '#markup' => '<div><strong>' . $this->t('Nodos a eliminar:') . '</strong> ' . $counts['to_delete'] . '</div>',
    ];

    // Botón de simulación (dry run) y de ejecución real.
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['dry_run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Simular eliminación (Dry Run)'),
      '#submit' => ['::submitDryRun'],
    ];
    $form['actions']['delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Eliminar nodos'),
      '#submit' => ['::submitDelete'],
      '#attributes' => ['class' => ['button--danger']],
    ];

    return $form;
  }

  /**
   * AJAX callback para actualizar el conteo de nodos.
   */
  public function updateNodeCount(array &$form, FormStateInterface $form_state) {
    return $form['node_count'];
  }

  /**
   * Convierte las fechas del formulario a timestamps UTC.
   * Si $is_end es TRUE, pone la hora en 23:59:59, si es FALSE en 00:00:00.
   */
  protected function convertFormDates($date_value, $is_end = FALSE) {
    if (empty($date_value)) {
      return NULL;
    }
    
    // Si es un string (formato YYYY-MM-DD)
    if (is_string($date_value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_value)) {
      $date_string = $date_value . ($is_end ? ' 23:59:59' : ' 00:00:00');
      return strtotime($date_string . ' UTC');
    }
    // Si es un DrupalDateTime
    if ($date_value instanceof DrupalDateTime) {
      if ($is_end) {
        $date_value->setTime(23, 59, 59);
      } else {
        $date_value->setTime(0, 0, 0);
      }
      return $date_value->getTimestamp();
    }
    // Si es un array con 'date'
    if (is_array($date_value) && isset($date_value['date'])) {
      $date_string = $date_value['date'] . ($is_end ? ' 23:59:59' : ' 00:00:00');
      return strtotime($date_string . ' UTC');
    }
    // Fallback
    return NULL;
  }

  /**
   * Obtiene el número de nodos actuales y los que se eliminarían.
   */
  protected function getNodeCounts(FormStateInterface $form_state) {
    $content_type = $form_state->getValue('content_type');
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    $start = $this->convertFormDates($start_date, FALSE);
    $end = $this->convertFormDates($end_date, TRUE);
    return $this->bulkDeleteService->getNodeCounts($content_type, $start, $end);
  }

  /**
   * Genera un archivo CSV con información de nodos.
   */
  protected function generateCsv($nodes_info, $filename_prefix) {
    $file_system = \Drupal::service('file_system');
    $public_path = \Drupal::service('file_system')->realpath('public://');
    $filename = $filename_prefix . '_' . date('Y-m-d_H-i-s') . '.csv';
    $file_path = $public_path . '/' . $filename;
    
    $handle = fopen($file_path, 'w');
    if (!$handle) {
      return FALSE;
    }
    
    // Escribir encabezados
    fputcsv($handle, ['Node ID', 'Path']);
    
    // Escribir datos
    foreach ($nodes_info as $node_info) {
      fputcsv($handle, [$node_info['nid'], $node_info['path']]);
    }
    
    fclose($handle);
    
    return 'public://' . $filename;
  }

  /**
   * Submit handler para simulación (dry run).
   */
  public function submitDryRun(array &$form, FormStateInterface $form_state) {
    $content_type = $form_state->getValue('content_type');
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    
    $start = $this->convertFormDates($start_date, FALSE);
    $end = $this->convertFormDates($end_date, TRUE);
    
    if (!$content_type || !$start || !$end) {
      $this->messenger()->addError($this->t('Debe seleccionar un tipo de contenido y un rango de fechas válido.'));
      return;
    }
    
    $nodes_info = $this->bulkDeleteService->getNodesInfo($content_type, $start, $end);
    $count = count($nodes_info);
    
    if ($count === 0) {
      $this->messenger()->addStatus($this->t('Simulación: No se encontraron nodos para eliminar.'));
      return;
    }
    
    // Configurar el batch para simulación
    $this->setupBatchDryRun($nodes_info, $content_type);
  }

  /**
   * Submit handler para eliminación real.
   */
  public function submitDelete(array &$form, FormStateInterface $form_state) {
    $content_type = $form_state->getValue('content_type');
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    
    $start = $this->convertFormDates($start_date, FALSE);
    $end = $this->convertFormDates($end_date, TRUE);
    
    if (!$content_type || !$start || !$end) {
      $this->messenger()->addError($this->t('Debe seleccionar un tipo de contenido y un rango de fechas válido.'));
      return;
    }
    
    // Obtener información de nodos antes de eliminarlos
    $nodes_info = $this->bulkDeleteService->getNodesInfo($content_type, $start, $end);
    
    if (empty($nodes_info)) {
      $this->messenger()->addStatus($this->t('No se encontraron nodos para eliminar o el tipo de contenido no es permitido.'));
      return;
    }
    
    // Generar CSV antes de eliminar
    $csv_file = $this->generateCsv($nodes_info, 'deleted_nodes');
    
    // Configurar el batch para eliminación
    $this->setupBatchDelete($nodes_info, $content_type, $csv_file);
  }

  /**
   * Configura el batch para eliminación real.
   */
  protected function setupBatchDelete($nodes_info, $content_type, $csv_file) {
    $batch_size = 20; // Procesar 20 nodos por lote
    $operations = [];
    
    // Dividir nodos en lotes
    $chunks = array_chunk($nodes_info, $batch_size);
    $total_nodes = count($nodes_info);
    
    foreach ($chunks as $chunk) {
      $operations[] = [
        [static::class, 'batchProcessDelete'],
        [$chunk, $content_type, $csv_file, $total_nodes]
      ];
    }
    
    $batch = [
      'title' => $this->t('Eliminando @total nodos...', ['@total' => $total_nodes]),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
      'init_message' => $this->t('Iniciando eliminación de @total nodos...', ['@total' => $total_nodes]),
      'progress_message' => $this->t('Procesado @current de @total lotes.'),
      'error_message' => $this->t('Error durante la eliminación de nodos.'),
    ];
    
    batch_set($batch);
  }
  
  /**
   * Configura el batch para simulación (dry run).
   */
  protected function setupBatchDryRun($nodes_info, $content_type) {
    $batch_size = 50; // Procesar 50 nodos por lote en simulación
    $operations = [];
    
    // Generar CSV antes del batch para simulación
    $csv_file = $this->generateCsv($nodes_info, 'dry_run_nodes');
    $total_nodes = count($nodes_info);
    
    // Dividir nodos en lotes
    $chunks = array_chunk($nodes_info, $batch_size);
    
    foreach ($chunks as $chunk) {
      $operations[] = [
        [static::class, 'batchProcessDryRun'],
        [$chunk, $content_type, $csv_file, $total_nodes]
      ];
    }
    
    $batch = [
      'title' => $this->t('Simulando eliminación de @total nodos...', ['@total' => $total_nodes]),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinishedDryRun'],
      'init_message' => $this->t('Iniciando simulación de @total nodos...', ['@total' => $total_nodes]),
      'progress_message' => $this->t('Procesado @current de @total lotes.'),
      'error_message' => $this->t('Error durante la simulación.'),
    ];
    
    batch_set($batch);
  }
  
  /**
   * Procesa un lote de nodos para eliminación.
   */
  public static function batchProcessDelete($nodes_chunk, $content_type, $csv_file, $total_nodes, &$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['deleted'] = 0;
      $context['results']['content_type'] = $content_type;
      $context['results']['csv_file'] = $csv_file;
      $context['results']['deleted_count'] = 0;
      $context['results']['total_nodes'] = $total_nodes;
    }
    
    $start_time = microtime(true);
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $nids = array_column($nodes_chunk, 'nid');
    
    try {
      // Optimización: Eliminar directamente por ID sin cargar entidades completas
      $query = \Drupal::database()->delete('node')
        ->condition('nid', $nids, 'IN');
      $deleted_count = $query->execute();
      
      // Eliminar revisiones también
      \Drupal::database()->delete('node_revision')
        ->condition('nid', $nids, 'IN')
        ->execute();
      
      // Eliminar datos de campo (esto puede ser lo que causa la lentitud)
      $field_storage_definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('node');
      foreach ($field_storage_definitions as $field_name => $field_storage) {
        if ($field_storage->getType() !== 'entity_reference') {
          continue;
        }
        
        $table_name = 'node__' . $field_name;
        if (\Drupal::database()->schema()->tableExists($table_name)) {
          \Drupal::database()->delete($table_name)
            ->condition('entity_id', $nids, 'IN')
            ->execute();
        }
        
        $revision_table_name = 'node_revision__' . $field_name;
        if (\Drupal::database()->schema()->tableExists($revision_table_name)) {
          \Drupal::database()->delete($revision_table_name)
            ->condition('entity_id', $nids, 'IN')
            ->execute();
        }
      }
      
      // Limpiar cache
      \Drupal::service('entity_type.manager')->getStorage('node')->resetCache($nids);
      
      $context['sandbox']['deleted'] += $deleted_count;
      $context['results']['deleted_count'] += $deleted_count;
      
      $end_time = microtime(true);
      $execution_time = round(($end_time - $start_time) * 1000, 2); // en milisegundos
      
      $context['message'] = t('Eliminados @deleted de @total nodos (último lote: @time ms)...', [
        '@deleted' => $context['sandbox']['deleted'],
        '@total' => $total_nodes,
        '@time' => $execution_time
      ]);
      
      // Log para depuración
      \Drupal::logger('node_bulk_delete')->info('Lote eliminado: @count nodos en @time ms', [
        '@count' => $deleted_count,
        '@time' => $execution_time
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('node_bulk_delete')->error('Error en batch delete: @error', ['@error' => $e->getMessage()]);
      $context['message'] = t('Error al eliminar nodos: @error', ['@error' => $e->getMessage()]);
    }
    
    $context['sandbox']['progress']++;
  }
  
  /**
   * Procesa un lote de nodos para simulación.
   */
  public static function batchProcessDryRun($nodes_chunk, $content_type, $csv_file, $total_nodes, &$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['processed'] = 0;
      $context['results']['content_type'] = $content_type;
      $context['results']['total_count'] = 0;
      $context['results']['csv_file'] = $csv_file;
      $context['results']['total_nodes'] = $total_nodes;
    }
    
    $processed_count = count($nodes_chunk);
    $context['sandbox']['processed'] += $processed_count;
    $context['results']['total_count'] += $processed_count;
    
    $context['message'] = t('Simulados @processed de @total nodos...', [
      '@processed' => $context['sandbox']['processed'],
      '@total' => $total_nodes
    ]);
    $context['sandbox']['progress']++;
  }
  
  /**
   * Callback cuando termina el batch de eliminación.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      $deleted_count = isset($results['deleted_count']) ? $results['deleted_count'] : 0;
      $total_expected = isset($results['total_nodes']) ? $results['total_nodes'] : $deleted_count;
      
      if ($deleted_count === $total_expected) {
        $message = \Drupal::translation()->formatPlural(
          $deleted_count,
          'Se eliminó 1 nodo del tipo @type.',
          'Se eliminaron @count nodos del tipo @type.',
          [
            '@count' => $deleted_count,
            '@type' => $results['content_type'],
          ]
        );
      } else {
        $message = t('Se eliminaron @deleted de @total nodos del tipo @type.', [
          '@deleted' => $deleted_count,
          '@total' => $total_expected,
          '@type' => $results['content_type'],
        ]);
      }
      
      if (!empty($results['csv_file'])) {
        $message .= ' ' . t('Se generó archivo CSV: @file', ['@file' => $results['csv_file']]);
      }
      
      \Drupal::messenger()->addStatus($message);
    } else {
      \Drupal::messenger()->addError(t('Error durante la eliminación de nodos.'));
    }
  }
  
  /**
   * Callback cuando termina el batch de simulación.
   */
  public static function batchFinishedDryRun($success, $results, $operations) {
    if ($success) {
      $total_count = isset($results['total_count']) ? $results['total_count'] : 0;
      $total_expected = isset($results['total_nodes']) ? $results['total_nodes'] : $total_count;
      
      $message = \Drupal::translation()->formatPlural(
        $total_expected,
        'Simulación: Se eliminaría 1 nodo del tipo @type.',
        'Simulación: Se eliminarían @count nodos del tipo @type.',
        [
          '@count' => $total_expected,
          '@type' => $results['content_type'],
        ]
      );
      
      if (!empty($results['csv_file'])) {
        $message .= ' ' . t('Se generó archivo CSV: @file', ['@file' => $results['csv_file']]);
      } else {
        $message .= ' ' . t('Error al generar archivo CSV.');
      }
      
      \Drupal::messenger()->addStatus($message);
    } else {
      \Drupal::messenger()->addError(t('Error durante la simulación.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Este método es requerido por FormBase, pero no se usa porque se definen handlers personalizados.
  }
}