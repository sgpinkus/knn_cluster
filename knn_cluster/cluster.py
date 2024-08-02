#!/usr/bin/env python
import sys
import numpy as np
from collections import OrderedDict
import importlib
from knn_cluster.knn import knn_cluster, KNNSearch
from knn_cluster import distances, conditions
from pydash import merge
import json
import argparse


np_printoptions = {
  'linewidth': 1e6,
  'threshold': 1e6,
  'formatter': {
    'float_kind': lambda v: '%+0.3f' % (v,),
    'int_kind': lambda v: '%+0.3f' % (v,),
  },
}
np.set_printoptions(**np_printoptions)


def main(argv):
  ''' Print clustering to stdout. First line is the config for reproduction. '''
  config = load_config(argv)
  (data, distance, conditions, j, k, src_config) = config.values()
  index = KNNSearch(data, distance)
  knns = index.knns(k)
  print(json.dumps(src_config))
  for _k in range(j, k+1):
    n, clustering = knn_cluster(data, knns, _k, conditions)
    print(f'{_k}:{','.join([str(i) for i in clustering])}')


def load_config(argv):
  parser = get_argparser()
  args = parser.parse_args(argv)
  cli_config = { k: v for k, v in vars(args).items() if v is not None }
  config = load_module(cli_config['config_file']).config
  config = merge(config, cli_config)
  data = load_module(config['data_file']).data
  distance = getattr(distances, config['distance']) if 'distance' in config else None
  if not 'j' in config: config['j'] = config['k']
  _conditions = []
  if 'conditions' in config:
    for c in config['conditions']:
      _conditions.append(getattr(conditions, c))
  return OrderedDict({
    'data': data,
    'distance': distance,
    'conditions': _conditions,
    'j': config['j'],
    'k': config['k'],
    'src_config': config
  })



def get_argparser():
  parser = argparse.ArgumentParser(prog='knn_cluster cluster')
  parser.add_argument('-c', '--config-file', nargs='?', type=str, default='knn_cluster.config')
  parser.add_argument('-d', '--data-file', nargs='?', type=str)
  parser.add_argument('-j', type=int, help='A clustering will be output for j to k')
  parser.add_argument('-k', type=int, help='A clustering will be output for j to k')
  return parser


def load_module(module_file):
  def make_module_path(s):
    ''' Convert a possible filepath to a module-path. Does nothing it s is already a module-path '''
    return s.replace('.py', '').replace('/', '.').replace('..', '.').lstrip('.')
  config = importlib.import_module(make_module_path(module_file))
  return config


if __name__ == '__main__':
  main(sys.argv[1:])